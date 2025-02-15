
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#if __has_include(<jsoncpp/json/json.h>)
#include <jsoncpp/json/json.h>
#elif __has_include(<json/json.h>)
#include <json/json.h>
#endif

#include "FPPOLEDUtils.h"
#include "OLEDPages.h"
#include "common.h"
#include "settings.h"

#if defined(PLATFORM_BBB)
#include "util/BBBUtils.h"
#define PLAT_GPIO_CLASS BBBPinProvider
#elif defined(PLATFORM_PI)
#include "util/PiGPIOUtils.h"
#define PLAT_GPIO_CLASS PiGPIOPinProvider
#else
#include "util/TmpFileGPIO.h"
#define PLAT_GPIO_CLASS TmpFilePinProvider
#endif

static FPPOLEDUtils* oled = nullptr;
void sigInteruptHandler(int sig) {
    oled->cleanup();
    OLEDPage::displayOff();
    exit(1);
}

void sigTermHandler(int sig) {
    oled->cleanup();
    OLEDPage::displayOff();
    exit(0);
}

int main(int argc, char* argv[]) {
    PinCapabilities::InitGPIO("FPPOLED", new PLAT_GPIO_CLASS());
    printf("FPP OLED Status Display Driver\n");
    LoadSettings(argv[0]);

    int ledType = getSettingInt("LEDDisplayType");
    printf("    Led Type: %d\n", ledType);
    fflush(stdout);

    if (!OLEDPage::InitializeDisplay(ledType)) {
        ledType = 0;
    }

    struct sigaction sigIntAction;
    sigIntAction.sa_handler = sigInteruptHandler;
    sigemptyset(&sigIntAction.sa_mask);
    sigIntAction.sa_flags = 0;
    sigaction(SIGINT, &sigIntAction, NULL);

    struct sigaction sigTermAction;
    sigTermAction.sa_handler = sigTermHandler;
    sigemptyset(&sigTermAction.sa_mask);
    sigTermAction.sa_flags = 0;
    sigaction(SIGTERM, &sigTermAction, NULL);

    int count = 0;
    oled = new FPPOLEDUtils(ledType);
    oled->run();
}
