// NAMAA sensor node: every feature switch and every pin, in one place.
//
// Include after <Arduino.h> (the board target macros come from it).
// Every value can be overridden from platformio.ini build_flags, e.g.
//   -DFEATURE_FLOW=0  -DDHT_TYPE=22  -DPIN_RELAY=23  -DRELAY_ACTIVE_HIGH=0
//
// Two profiles
// ------------
// STAND_PROFILE=1 (envs esp32, esp32s3): the demo stand (Bench A). Soil probe,
//   relay and status LED only; raw soil every 2 s; pump MANUAL ONLY from the
//   dashboard (any local_rule from the server is ignored); relay OFF on boot,
//   OFF after 10 s without a server reply, and never on longer than pump_max_s
//   (default 30 s) per run. Relay active-LOW by default.
// STAND_PROFILE=0 (envs esp32_field, esp32s3_field): the field node of Sprint 4
//   (soil, DHT, flow, relay, LCD, offline local rule).
//
// Demo stand pin map (STAND_PROFILE=1)
// ------------------------------------
// | Part                  | Classic ESP32 DevKit (esp32) | ESP32-S3 (esp32s3)          |
// |-----------------------|------------------------------|-----------------------------|
// | Soil AOUT (analog)    | GPIO34 (ADC1_CH6, input-only)| GPIO4 (ADC1_CH3)            |
// | Relay IN              | GPIO26                       | GPIO5                       |
// | Status LED            | GPIO2 (on-board blue LED)    | GPIO48 on-board RGB LED     |
// Soil VCC -> 3.3 V (its AOUT then stays below 3.3 V), relay VCC -> 5 V (VIN),
// pump on its own 5 V supply through relay COM -> NO, all grounds common.
//
// Field pin map (STAND_PROFILE=0)
// -------------------------------
// | Part                         | Classic ESP32 (esp32) | ESP32-S3 (esp32s3) | Notes                                 |
// |------------------------------|-----------------------|--------------------|---------------------------------------|
// | Soil moisture (analog)       | GPIO34                | GPIO4              | ADC1 only (ADC2 is dead with Wi-Fi)   |
// | Water level (analog)         | GPIO35                | GPIO5              | ADC1 only                             |
// | DHT11 / DHT22 data           | GPIO27                | GPIO16             | 10K pull-up to 3.3 V                  |
// | Flow sensor pulses           | GPIO26                | GPIO17             | 5 V pulses: 10K + 20K divider to 3.3 V|
// | Relay IN (pump)              | GPIO25                | GPIO18             | pump on its own supply, never the board|
// | LCD1602 I2C SDA              | GPIO21                | GPIO8              | I2C address 0x27, 0x3F tried at boot  |
// | LCD1602 I2C SCL              | GPIO22                | GPIO9              |                                       |
// The pin checks below are feature-aware: a disabled part never blocks a pin
// (e.g. the stand relay GPIO26 on the classic ESP32 is the field flow pin).
//
// Pins never used:
//   classic: 0, 2, 5, 12, 15 (strapping), 6-11 (flash), 1, 3 (serial).
//            ADC1 = GPIO32-39. GPIO34-39 are input-only, fine for analog.
//   S3:      0, 3, 45, 46 (strapping), 26-32 (flash), 33-37 (octal PSRAM on N16R8),
//            19, 20 (USB), 43, 44 (UART0), 38, 48 (RGB LED). ADC1 = GPIO1-10.
// All ESP32 pins are 3.3 V: any 5 V signal (flow sensor pulses) needs a divider.
//   classic GPIO2 is a strapping pin but carries the on-board LED already; it is
//   only driven after boot, so flashing is not affected.
#pragma once

// ---- Profile --------------------------------------------------------------------
#ifndef STAND_PROFILE
#define STAND_PROFILE 0        // platformio.ini sets 1 for esp32 / esp32s3 (the demo stand)
#endif

#if STAND_PROFILE
// Stand defaults; each one can still be overridden with -D...
  #ifndef FEATURE_DHT
  #define FEATURE_DHT 0
  #endif
  #ifndef FEATURE_FLOW
  #define FEATURE_FLOW 0
  #endif
  #ifndef FEATURE_WATER_LEVEL
  #define FEATURE_WATER_LEVEL 0
  #endif
  #ifndef FEATURE_LCD
  #define FEATURE_LCD 0
  #endif
  #ifndef FEATURE_STATUS_LED
  #define FEATURE_STATUS_LED 1
  #endif
  #ifndef RELAY_ACTIVE_HIGH
  #define RELAY_ACTIVE_HIGH 0   // most cheap 1-channel 5 V modules trigger on LOW. Unknown for
  #endif                        // the exact module: if it is ON when it should be OFF, build with 1
  #ifndef ADC_SAMPLES
  #define ADC_SAMPLES 10
  #endif
  #ifndef REPORT_INTERVAL_DEFAULT_S
  #define REPORT_INTERVAL_DEFAULT_S 2   // raw soil every 2 s; the reply's interval_s still wins
  #endif
  #ifndef HTTP_TIMEOUT_MS
  #define HTTP_TIMEOUT_MS 3000          // short: the 10 s link timeout needs several tries
  #endif
#endif

#ifndef STAND_PUMP_MAX_S
#define STAND_PUMP_MAX_S 30    // board-side cap per run; the reply's pump_max_s replaces it
#endif
#ifndef STAND_LINK_TIMEOUT_S
#define STAND_LINK_TIMEOUT_S 10  // relay OFF when no successful server reply for this long
#endif

// ---- Features (1 = fitted, 0 = absent: the value is sent as null) ------------
#ifndef FEATURE_SOIL
#define FEATURE_SOIL 1         // capacitive soil moisture probe (analog)
#endif
#ifndef FEATURE_DHT
#define FEATURE_DHT 1          // DHT11 or DHT22 temperature + humidity
#endif
#ifndef FEATURE_RELAY
#define FEATURE_RELAY 1        // 1-channel relay driving the pump
#endif
#ifndef FEATURE_FLOW
#define FEATURE_FLOW 1         // YF-S401 / YF-S201 hall flow sensor
#endif
#ifndef FEATURE_WATER_LEVEL
#define FEATURE_WATER_LEVEL 0  // analog tank level probe (HW-038), optional, off by default
#endif
#ifndef FEATURE_LCD
#define FEATURE_LCD 1          // 16x2 I2C LCD; harmless if none answers on the bus
#endif
#ifndef FEATURE_STATUS_LED
#define FEATURE_STATUS_LED 0   // on-board LED: steady = online, fast blink = offline, slow = pump on
#endif

// ---- Sensor constants (unknowns: check against the real parts) ---------------
#ifndef DHT_TYPE
#define DHT_TYPE 11            // 11 = DHT11, 22 = DHT22
#endif
#ifndef RELAY_ACTIVE_HIGH
#define RELAY_ACTIVE_HIGH 1    // 1: IN=HIGH closes the relay (HW-482 style); 0: low-level trigger modules
#endif
#ifndef FLOW_PULSES_PER_LITRE
#define FLOW_PULSES_PER_LITRE 5880.0f  // YF-S401 ~5880; YF-S201 ~450. Calibrate with a measuring jug
#endif
#ifndef LCD_I2C_ADDR
#define LCD_I2C_ADDR 0x27      // tried first; 0x3F is tried if nothing answers at this address
#endif
#ifndef LCD_I2C_ADDR_ALT
#define LCD_I2C_ADDR_ALT 0x3F
#endif
#ifndef SOIL_DRY_RAW
#define SOIL_DRY_RAW 3000      // raw ADC in dry air: used only for the LCD % and offline watering
#endif                         // (server keeps its own calibration). Change with `set cal <dry> <wet>`
#ifndef SOIL_WET_RAW
#define SOIL_WET_RAW 1300      // raw ADC in a glass of water
#endif
#ifndef ADC_SAMPLES
#define ADC_SAMPLES 10         // analog readings averaged per value
#endif

// ---- Pins ---------------------------------------------------------------------
#if defined(CONFIG_IDF_TARGET_ESP32S3)
  #define BOARD_NAME "esp32s3"
  #ifndef PIN_SOIL
  #define PIN_SOIL 4           // ADC1_CH3
  #endif
  #ifndef PIN_WATER_LEVEL
  #define PIN_WATER_LEVEL 5    // ADC1_CH4
  #endif
  #ifndef PIN_DHT
  #define PIN_DHT 16
  #endif
  #ifndef PIN_FLOW
  #define PIN_FLOW 17          // via 10K + 20K divider
  #endif
  #ifndef PIN_RELAY
    #if STAND_PROFILE
    #define PIN_RELAY 5        // stand
    #else
    #define PIN_RELAY 18       // field
    #endif
  #endif
  #ifndef PIN_I2C_SDA
  #define PIN_I2C_SDA 8
  #endif
  #ifndef PIN_I2C_SCL
  #define PIN_I2C_SCL 9
  #endif
  #ifndef PIN_STATUS_LED
  #define PIN_STATUS_LED 48    // DevKitC-1 v1.0 on-board WS2812 RGB LED (v1.1 boards: 38)
  #endif
  #ifndef STATUS_LED_RGB
  #define STATUS_LED_RGB 1     // addressable RGB LED, driven with neopixelWrite()
  #endif
#elif defined(CONFIG_IDF_TARGET_ESP32)
  #define BOARD_NAME "esp32"
  #ifndef PIN_SOIL
  #define PIN_SOIL 34          // ADC1_CH6, input-only
  #endif
  #ifndef PIN_WATER_LEVEL
  #define PIN_WATER_LEVEL 35   // ADC1_CH7, input-only
  #endif
  #ifndef PIN_DHT
  #define PIN_DHT 27
  #endif
  #ifndef PIN_FLOW
  #define PIN_FLOW 26          // via 10K + 20K divider
  #endif
  #ifndef PIN_RELAY
    #if STAND_PROFILE
    #define PIN_RELAY 26       // stand
    #else
    #define PIN_RELAY 25       // field
    #endif
  #endif
  #ifndef PIN_I2C_SDA
  #define PIN_I2C_SDA 21
  #endif
  #ifndef PIN_I2C_SCL
  #define PIN_I2C_SCL 22
  #endif
  #ifndef PIN_STATUS_LED
  #define PIN_STATUS_LED 2     // on-board blue LED of the DevKit (active HIGH)
  #endif
  #ifndef STATUS_LED_RGB
  #define STATUS_LED_RGB 0
  #endif
#else
  #error "Unsupported board: build with -e esp32 or -e esp32s3"
#endif

// ---- Compile-time pin checks -------------------------------------------------
#if defined(CONFIG_IDF_TARGET_ESP32S3)
  #if FEATURE_SOIL && (PIN_SOIL < 1 || PIN_SOIL > 10)
    #error "PIN_SOIL must be an ADC1 pin (GPIO1-10) on the S3"
  #endif
  #if FEATURE_WATER_LEVEL && (PIN_WATER_LEVEL < 1 || PIN_WATER_LEVEL > 10)
    #error "PIN_WATER_LEVEL must be an ADC1 pin (GPIO1-10) on the S3"
  #endif
#else
  #if FEATURE_SOIL && (PIN_SOIL < 32 || PIN_SOIL > 39)
    #error "PIN_SOIL must be an ADC1 pin (GPIO32-39) on the classic ESP32"
  #endif
  #if FEATURE_WATER_LEVEL && (PIN_WATER_LEVEL < 32 || PIN_WATER_LEVEL > 39)
    #error "PIN_WATER_LEVEL must be an ADC1 pin (GPIO32-39) on the classic ESP32"
  #endif
#endif
// Each pin of an enabled part, or -1 when the part is off: two enabled parts
// never share a pin.
#define NAMAA_P_SOIL   (FEATURE_SOIL ? PIN_SOIL : -1)
#define NAMAA_P_LEVEL  (FEATURE_WATER_LEVEL ? PIN_WATER_LEVEL : -2)
#define NAMAA_P_DHT    (FEATURE_DHT ? PIN_DHT : -3)
#define NAMAA_P_FLOW   (FEATURE_FLOW ? PIN_FLOW : -4)
#define NAMAA_P_RELAY  (FEATURE_RELAY ? PIN_RELAY : -5)
#define NAMAA_P_SDA    (FEATURE_LCD ? PIN_I2C_SDA : -6)
#define NAMAA_P_SCL    (FEATURE_LCD ? PIN_I2C_SCL : -7)
#define NAMAA_P_LED    (FEATURE_STATUS_LED ? PIN_STATUS_LED : -8)
#if NAMAA_P_SOIL == NAMAA_P_LEVEL || NAMAA_P_SOIL == NAMAA_P_DHT || NAMAA_P_SOIL == NAMAA_P_FLOW || \
    NAMAA_P_SOIL == NAMAA_P_RELAY || NAMAA_P_SOIL == NAMAA_P_SDA || NAMAA_P_SOIL == NAMAA_P_SCL || \
    NAMAA_P_SOIL == NAMAA_P_LED
  #error "Soil pin shared with another enabled part"
#endif
#if NAMAA_P_LEVEL == NAMAA_P_DHT || NAMAA_P_LEVEL == NAMAA_P_FLOW || NAMAA_P_LEVEL == NAMAA_P_RELAY || \
    NAMAA_P_LEVEL == NAMAA_P_SDA || NAMAA_P_LEVEL == NAMAA_P_SCL || NAMAA_P_LEVEL == NAMAA_P_LED
  #error "Water level pin shared with another enabled part"
#endif
#if NAMAA_P_DHT == NAMAA_P_FLOW || NAMAA_P_DHT == NAMAA_P_RELAY || NAMAA_P_DHT == NAMAA_P_SDA || \
    NAMAA_P_DHT == NAMAA_P_SCL || NAMAA_P_DHT == NAMAA_P_LED
  #error "DHT pin shared with another enabled part"
#endif
#if NAMAA_P_FLOW == NAMAA_P_RELAY || NAMAA_P_FLOW == NAMAA_P_SDA || NAMAA_P_FLOW == NAMAA_P_SCL || \
    NAMAA_P_FLOW == NAMAA_P_LED
  #error "Flow pin shared with another enabled part"
#endif
#if NAMAA_P_RELAY == NAMAA_P_SDA || NAMAA_P_RELAY == NAMAA_P_SCL || NAMAA_P_RELAY == NAMAA_P_LED
  #error "Relay pin shared with another enabled part"
#endif
#if NAMAA_P_SDA == NAMAA_P_SCL || NAMAA_P_SDA == NAMAA_P_LED || NAMAA_P_SCL == NAMAA_P_LED
  #error "I2C pin shared with another enabled part"
#endif
#if !defined(CONFIG_IDF_TARGET_ESP32S3) && FEATURE_RELAY && PIN_RELAY >= 34
  #error "PIN_RELAY must be an output pin (GPIO34-39 are input-only on the classic ESP32)"
#endif

// ---- Timing -------------------------------------------------------------------
#ifndef REPORT_INTERVAL_DEFAULT_S
#define REPORT_INTERVAL_DEFAULT_S 30  // used after boot and after a failed post; the reply's interval_s wins
#endif
#ifndef HTTP_TIMEOUT_MS
#define HTTP_TIMEOUT_MS 5000
#endif
#ifndef WIFI_CONNECT_TIMEOUT_MS
#define WIFI_CONNECT_TIMEOUT_MS 15000  // per network before trying the other one
#endif
#ifndef USB_AUTO_AFTER_WIFI_FAILS
#define USB_AUTO_AFTER_WIFI_FAILS 10   // failed connect rounds before falling back to USB mode
#endif
#ifndef USB_REPLY_TIMEOUT_MS
#define USB_REPLY_TIMEOUT_MS 5000      // wait for NAMAA-REPLY: before counting a failed post
#endif
#ifndef WDT_TIMEOUT_S
#define WDT_TIMEOUT_S 30               // task watchdog: reboot if loop() hangs this long
#endif
#ifndef OFFLINE_BUFFER_SIZE
#define OFFLINE_BUFFER_SIZE 180        // readings kept in RAM while offline (>= 120)
#endif
#ifndef FLUSH_PER_CYCLE
#define FLUSH_PER_CYCLE 10             // buffered readings sent per report (rate limit 120/min)
#endif
#ifndef NTP_SERVER_1
#define NTP_SERVER_1 "pool.ntp.org"
#endif
#ifndef NTP_SERVER_2
#define NTP_SERVER_2 "time.google.com"
#endif
#ifndef FW_VERSION
#define FW_VERSION "namaa-sensor-dev"
#endif
