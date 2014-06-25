/*
 *   output channel thread for Falcon Pi Player (FPP)
 *
 *   Copyright (C) 2013 the Falcon Pi Player Developers
 *      Initial development by:
 *      - David Pitts (dpitts)
 *      - Tony Mace (MyKroFt)
 *      - Mathew Mrosko (Materdaddy)
 *      - Chris Pinkham (CaptainMurdoch)
 *      For additional credits and developers, see credits.php.
 *
 *   The Falcon Pi Player (FPP) is free software; you can redistribute it
 *   and/or modify it under the terms of the GNU General Public License
 *   as published by the Free Software Foundation; either version 2 of
 *   the License, or (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program; if not, see <http://www.gnu.org/licenses/>.
 */

#include <errno.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <string.h>
#include <sys/time.h>
#include <unistd.h>

#include "channeloutput.h"
#include "common.h"
#include "controlsend.h"
#include "effects.h"
#include "log.h"
#include "memorymap.h"
#include "sequence.h"
#include "settings.h"

/* used by external sync code */
int   RefreshRate = 20;
int   DefaultLightDelay = 0;
int   LightDelay = 0;
int   MasterFramesPlayed = 0;

/* local variables */
pthread_t ChannelOutputThreadID;
int       RunThread = 0;
int       ThreadIsRunning = 0;

/* Master/Remote sync */
int             InitSync = 1;
pthread_mutex_t SyncLock;
pthread_cond_t  SyncCond;

/*
 * Check to see if the channel output thread is running
 */
inline int ChannelOutputThreadIsRunning(void) {
	return ThreadIsRunning;
}

/*
 * Initialize Master/Remote Sync variables
 */
void InitChannelOutputSyncVars(void) {
	pthread_mutex_init(&SyncLock, NULL);
	pthread_cond_init(&SyncCond, NULL);
}

/*
 * Destroy the Master/Remote Sync variables
 */
void DestroyChannelOutputSyncVars(void) {
	pthread_mutex_destroy(&SyncLock);
	pthread_cond_destroy(&SyncCond);
}

/*
 * Wait for a signal from the master
 */
void WaitForMaster(long long timeToWait) {
	struct timespec ts;
	struct timeval  tv;

	gettimeofday(&tv, NULL);
	ts.tv_sec  = tv.tv_sec;
	ts.tv_nsec = (tv.tv_usec + timeToWait) * 1000;

	if (ts.tv_nsec >= 1000000000)
	{
		ts.tv_sec  += 1;
		ts.tv_nsec -= 1000000000;
	}

	pthread_mutex_lock(&SyncLock);
	pthread_cond_timedwait(&SyncCond, &SyncLock, &ts);
	pthread_mutex_unlock(&SyncLock);
}

/*
 * Main loop in channel output thread
 */
void *RunChannelOutputThread(void *data)
{
	(void)data;

	static long long lastStatTime = 0;
	long long startTime;
	long long sendTime;
	long long readTime;
	int onceMore = 0;
	struct timespec ts;

	ThreadIsRunning = 1;
	while (RunThread)
	{
		startTime = GetTime();

		if ((getFPPmode() == MASTER_MODE) &&
			(IsSequenceRunning()))
			SendSeqSyncPacket(channelOutputFrame, mediaElapsedSeconds);

		SendSequenceData();
		sendTime = GetTime();

		if (getFPPmode() != BRIDGE_MODE)
			ReadSequenceData();

		readTime = GetTime();

		if ((IsSequenceRunning()) ||
			(IsEffectRunning()) ||
			(UsingMemoryMapInput()) ||
			(getFPPmode() == BRIDGE_MODE))
		{
			onceMore = 1;

			if (startTime > (lastStatTime + 1000000)) {
				int sleepTime = LightDelay - (GetTime() - startTime);
				lastStatTime = startTime;
				LogDebug(VB_CHANNELOUT,
					"Output Thread: Loop: %dus, Send: %lldus, Read: %lldus, Sleep: %dus\n",
					LightDelay, sendTime - startTime,
					readTime - sendTime, sleepTime);
			}
		}
		else
		{
			LightDelay = DefaultLightDelay;

			if (onceMore)
				onceMore = 0;
			else
				RunThread = 0;
		}

		if (getFPPmode() == REMOTE_MODE)
		{
			if (channelOutputFrame >= MasterFramesPlayed) {
				// We are too far ahead, wait for master to catch up
				while (channelOutputFrame >= MasterFramesPlayed)
				{
					WaitForMaster(DefaultLightDelay);
				}
			} else if (channelOutputFrame == (MasterFramesPlayed - 1)) {
				// We are right where we want to be, wait for master
				WaitForMaster(DefaultLightDelay);
			} else if (channelOutputFrame < (MasterFramesPlayed - 1)) {
				// We are too far behind, try to catch up by not sleeping
			}
		}
		else
		{
			// Calculate how long we need to nanosleep()
			ts.tv_sec = 0;
			ts.tv_nsec = (LightDelay - (GetTime() - startTime)) * 1000;
			nanosleep(&ts, NULL);
		}
	}

	ThreadIsRunning = 0;
	pthread_exit(NULL);
}

/*
 * Set the step time
 */
void SetChannelOutputRefreshRate(int rate)
{
	RefreshRate = rate;
}

/*
 * Kick off the channel output thread
 */
int StartChannelOutputThread(void)
{
	if (ChannelOutputThreadIsRunning())
		return 1;

	RunThread = 1;
	DefaultLightDelay = 1000000 / RefreshRate;
	LightDelay = DefaultLightDelay;

	int result = pthread_create(&ChannelOutputThreadID, NULL, &RunChannelOutputThread, NULL);

	if (result)
	{
		char msg[256];

		RunThread = 0;
		switch (result)
		{
			case EAGAIN: strcpy(msg, "Insufficient Resources");
				break;
			case EINVAL: strcpy(msg, "Invalid settings");
				break;
			case EPERM : strcpy(msg, "Invalid Permissions");
				break;
		}
		LogErr(VB_CHANNELOUT, "ERROR creating channel output thread: %s\n", msg );
	}
	else
	{
		pthread_detach(ChannelOutputThreadID);
	}
}

/*
 *
 */
int StopChannelOutputThread(void)
{
	int i = 0;

	// Stop the thread and wait a few seconds
	RunThread = 0;
	while (ThreadIsRunning && (i < 5))
	{
		sleep(1);
		i++;
	}

	// Didn't stop for some reason, so it was hung somewhere
	if (ThreadIsRunning)
		return -1;

	return 0;
}

/*
 * Update the count of frames that the master has played so we can sync to it
 */
void UpdateMasterPosition(int frameNumber)
{
	MasterFramesPlayed = frameNumber;
	pthread_cond_signal(&SyncCond);
}

/*
 * Calculate the new sync offset based on the current position reported
 * by the media player.
 */
void CalculateNewChannelOutputDelay(float mediaPosition)
{
	int expectedFramesSent = (int)(mediaPosition * RefreshRate);

	mediaElapsedSeconds = mediaPosition;

	LogDebug(VB_CHANNELOUT,
		"Media Position: %.2f, Frames Sent: %d, Expected: %d, Diff: %d\n",
		mediaPosition, channelOutputFrame, expectedFramesSent,
		channelOutputFrame - expectedFramesSent);

	int diff = channelOutputFrame - expectedFramesSent;
	if (diff)
	{
		int timerOffset = diff * 500;
		int newLightDelay = LightDelay;

		if (channelOutputFrame >  expectedFramesSent)
		{
			// correct if we slingshot past 0, otherwise offset further
			if (LightDelay < DefaultLightDelay)
				newLightDelay = DefaultLightDelay;
			else
				newLightDelay += timerOffset;
		}
		else
		{
			// correct if we slingshot past 0, otherwise offset further
			if (LightDelay > DefaultLightDelay)
				newLightDelay = DefaultLightDelay;
			else
				newLightDelay += timerOffset;
		}

		// This is bad if we hit this, but still don't let us go negative
		if (newLightDelay < 0)
			newLightDelay = 0;

		LogDebug(VB_CHANNELOUT, "LightDelay: %d, newLightDelay: %d\n",
			LightDelay, newLightDelay);
		LightDelay = newLightDelay;
	}
	else if (LightDelay != DefaultLightDelay)
	{
		LightDelay = DefaultLightDelay;
	}
}

