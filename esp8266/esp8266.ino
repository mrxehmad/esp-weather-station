/*
  ESP8266 Temperature Monitor - Final Compact Production Build
  ============================================================

  Goals implemented:
  - Very low flash wear: no routine EEPROM writes
  - RTC memory used for counters across resets
  - Static buffers, no steady-state String usage
  - Non-blocking Wi-Fi state machine
  - Wi-Fi reconnect backoff
  - HTTP upload backoff
  - Static HTTP response-body buffer
  - Rich JSON telemetry for future server-side updates
  - OTA password removed for simpler maintenance

  IMPORTANT:
  - OTA is OPEN in this build.
  - Use only on a trusted/isolated network, or put it behind firewall rules.
*/

#include <Arduino.h>
#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <ESP8266mDNS.h>
#include <ArduinoOTA.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>

#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <stdint.h>
#include <math.h>

#if USE_HTTPS
#include <WiFiClientSecure.h>
#endif

/*
  Optional OLED support is compile-time gated.
  If DISPLAY_ENABLED=0, no display code is included.
*/
#define DISPLAY_ENABLED 0

#if DISPLAY_ENABLED
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#endif

/* =========================
   Firmware metadata
   ========================= */
#define FW_VERSION "2026.07.29"
#define FW_HOSTNAME  "TempMonitor"

/* =========================
   Wi-Fi credentials
   ========================= */
static const char WIFI_SSID_P[] PROGMEM     = "ssid";
static const char WIFI_PASSWORD_P[] PROGMEM = "password";

static char g_ssid[33];
static char g_pass[65];

/* =========================
   Server endpoint
   ========================= */
#define USE_HTTPS 0

#if USE_HTTPS
static const char SERVER_URL[] PROGMEM = "https://temp.example.com/api/receive.php";

/*
  If you enable HTTPS, replace this with your certificate SHA-1 fingerprint
  as 20 raw bytes.
*/
static const uint8_t TLS_FINGERPRINT[20] = {
  0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF, 0x00, 0x11, 0x22, 0x33,
  0x44, 0x55, 0x66, 0x77, 0x88, 0x99, 0xAA, 0xBB, 0xCC, 0xDD
};
#else
static const char SERVER_URL[] PROGMEM = "http://temp.example.com/api/receive.php";
#endif

/* =========================
   Timing and retry policy
   ========================= */
static const unsigned long SEND_INTERVAL_BASE   = 10UL * 60UL * 1000UL;   // 10 min
static const unsigned long UPLOAD_RETRY_BASE    = 10UL * 1000UL;          // 10 s
static const unsigned long UPLOAD_RETRY_MAX     = 15UL * 60UL * 1000UL;   // 15 min

static const unsigned long WIFI_RETRY_BASE      = 5UL * 1000UL;           // 5 s
static const unsigned long WIFI_RETRY_MAX       = 15UL * 60UL * 1000UL;   // 15 min
static const unsigned long WIFI_CONNECT_TIMEOUT = 20000UL;                // 20 s

static const unsigned long SERVER_TIMEOUT_MS    = 8000UL;                 // 8 s
static const unsigned long TEMP_READ_INTERVAL   = 2000UL;                 // 2 s
static const unsigned long REBOOT_INTERVAL      = 24UL * 60UL * 60UL * 1000UL;
static const unsigned long LOOP_YIELD_MS        = 10UL;

static const uint8_t  ADC_SAMPLES               = 32;
static const uint8_t  MAX_CONSECUTIVE_FAILURES  = 20;
static const uint16_t LOW_HEAP_THRESHOLD        = 8000;

static const float    SPIKE_THRESHOLD_C         = 10.0f;
static const uint8_t  SPIKE_PERSIST_COUNT       = 3;

static const uint32_t RTC_SAVE_EVERY_UPLOADS    = 10;

/* =========================
   Hardware / sensor config
   ========================= */
#define OLED_RESET     -1
#define OLED_SDA       14
#define OLED_SCL       12
#define OLED_I2C_ADDR  0x3C

static const int   THERM_PIN          = A0;
static const float SERIES_RESISTOR    = 10000.0f;
static const float NOMINAL_RESISTANCE = 10000.0f;
static const float NOMINAL_TEMP       = 25.0f;
static const float B_COEFFICIENT      = 3425.0f;
static const float CAL_OFFSET         = -8.0f;

/* =========================
   RTC memory statistics
   -------------------------
   This is retained across resets,
   but NOT across power loss.

   This replaces EEPROM boot writes.
   ========================= */
#define RTC_MAGIC   0x544D4F4E
#define RTC_VERSION 1

struct RtcStats {
  uint32_t magic;
  uint32_t version;
  uint32_t bootCount;
  uint32_t totalUploads;
  uint32_t okUploads;
  uint32_t failUploads;
  uint32_t wifiReconnects;
  uint32_t otaUpdates;
  uint32_t crc;
};

static RtcStats g_rtc;

/* =========================
   Global runtime state
   ========================= */
static float    g_temperature = 0.0f;
static uint16_t g_adcRaw      = 0;

static uint32_t g_consecFails   = 0;
static uint32_t g_spikeRejects  = 0;
static float    g_spikeCand     = 0.0f;
static uint8_t  g_spikeCnt      = 0;

static unsigned long g_bootTime         = 0;
static unsigned long g_lastSendTime     = 0;
static unsigned long g_lastTempRead     = 0;
static unsigned long g_wifiConnectStart = 0;
static unsigned long g_wifiFailTime     = 0;
static unsigned long g_wifiRetryDelay   = WIFI_RETRY_BASE;
static unsigned long g_uploadRetryDelay = UPLOAD_RETRY_BASE;
static unsigned long g_lastSuccessTime  = 0;

static uint32_t g_lowestHeap = 0xFFFFFFFF;

static bool g_sendNow    = false;
static bool g_sendSoon   = false;
static bool g_rebootNow  = false;

enum WifiState : uint8_t {
  WIFI_ST_DISCONNECTED = 0,
  WIFI_ST_CONNECTING   = 1,
  WIFI_ST_CONNECTED    = 2,
  WIFI_ST_FAILED       = 3
};

static WifiState g_wifiState = WIFI_ST_DISCONNECTED;

static const char* const WIFI_STATE_STR[] = {
  "disconnected",
  "connecting",
  "connected",
  "failed"
};

/* =========================
   Static buffers
   ========================= */
static char g_lastError[80]    = "";
static char g_resetReason[48]  = "unknown";
static char g_sdk[32]          = "unknown";

static char g_ipStr[16]        = "0.0.0.0";
static char g_macStr[18]       = "";
static char g_bssidStr[18]     = "N/A";

static char g_urlBuf[128];
static char g_respBuf[96];
static char g_payload[1024];
static char g_html[2048];
static size_t g_htmlLen = 0;

/* =========================
   Web server
   ========================= */
ESP8266WebServer g_server(80);

/* =========================
   Display object
   ========================= */
#if DISPLAY_ENABLED
Adafruit_SSD1306 g_display(128, 64, &Wire, OLED_RESET);
#endif

/* =========================
   Forward declarations
   ========================= */
static void logErr(const char* ctx, const char* detail);
static void logInfo(const char* ctx, const char* msg);
static void sanitize(char* s);
static void rtcSave();
static void safeRestart(const char* reason);

/* =========================
   Small helpers
   ========================= */
static void inc32(uint32_t& v) {
  if (v == UINT32_MAX) v = 0;
  else ++v;
}

static void logErr(const char* ctx, const char* detail) {
  Serial.printf("[%lums] ERROR %s: %s | heap=%u lowest=%lu\n",
                millis(), ctx, detail,
                ESP.getFreeHeap(), (unsigned long)g_lowestHeap);

  snprintf(g_lastError, sizeof(g_lastError), "%s: %s", ctx, detail);
  sanitize(g_lastError);
}

static void logInfo(const char* ctx, const char* msg) {
  Serial.printf("[%lums] INFO  %s: %s\n", millis(), ctx, msg);
}

static void sanitize(char* s) {
  for (; *s; ++s) {
    unsigned char c = (unsigned char)*s;
    if (c < 32 || c == '"' || c == '\\') *s = ' ';
  }
}

static void ipToString(char* buf, size_t len, const IPAddress& ip) {
  snprintf(buf, len, "%u.%u.%u.%u", ip[0], ip[1], ip[2], ip[3]);
}

/* =========================
   RTC memory stats
   ========================= */
static uint32_t rtcCrc(const RtcStats* s) {
  const uint32_t* p = reinterpret_cast<const uint32_t*>(s);
  uint32_t crc = 0;

  size_t words = (sizeof(RtcStats) - sizeof(uint32_t)) / sizeof(uint32_t);
  for (size_t i = 0; i < words; ++i) {
    crc ^= p[i];
    crc = (crc << 1) | (crc >> 31);
  }
  return crc;
}

static void rtcSave() {
  g_rtc.magic   = RTC_MAGIC;
  g_rtc.version = RTC_VERSION;
  g_rtc.crc     = rtcCrc(&g_rtc);

  if (!ESP.rtcUserMemoryWrite(0, reinterpret_cast<uint32_t*>(&g_rtc), sizeof(g_rtc))) {
    logErr("RTC", "write failed");
  }
}

static void rtcLoad() {
  bool ok = ESP.rtcUserMemoryRead(0, reinterpret_cast<uint32_t*>(&g_rtc), sizeof(g_rtc));

  if (!ok ||
      g_rtc.magic != RTC_MAGIC ||
      g_rtc.version != RTC_VERSION ||
      rtcCrc(&g_rtc) != g_rtc.crc) {
    memset(&g_rtc, 0, sizeof(g_rtc));
    g_rtc.magic   = RTC_MAGIC;
    g_rtc.version = RTC_VERSION;
    logInfo("RTC", "initialized fresh counters");
  }

  if (g_rtc.bootCount == UINT32_MAX) g_rtc.bootCount = 0;
  inc32(g_rtc.bootCount);

  rtcSave();
}

/* =========================
   Safe restart
   ========================= */
static void safeRestart(const char* reason) {
  Serial.printf("[%lums] REBOOT: %s | heap=%u\n",
                millis(), reason, ESP.getFreeHeap());

  rtcSave();
  delay(200);
  ESP.restart();
}

/* =========================
   Boot-time caches
   ========================= */
static void cacheResetReason() {
  String s = ESP.getResetReason();
  snprintf(g_resetReason, sizeof(g_resetReason), "%s", s.c_str());
  sanitize(g_resetReason);
}

static void cacheSdk() {
  String s = ESP.getSdkVersion();
  snprintf(g_sdk, sizeof(g_sdk), "%s", s.c_str());
  sanitize(g_sdk);
}

static void cacheNetworkInfo() {
  ipToString(g_ipStr, sizeof(g_ipStr), WiFi.localIP());

  uint8_t mac[6];
  WiFi.macAddress(mac);
  snprintf(g_macStr, sizeof(g_macStr), "%02X:%02X:%02X:%02X:%02X:%02X",
           mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);

  uint8_t* b = WiFi.BSSID();
  if (b) {
    snprintf(g_bssidStr, sizeof(g_bssidStr), "%02X:%02X:%02X:%02X:%02X:%02X",
             b[0], b[1], b[2], b[3], b[4], b[5]);
  } else {
    strcpy(g_bssidStr, "N/A");
  }
}

static void trackHeap() {
  uint32_t h = ESP.getFreeHeap();
  if (h < g_lowestHeap) g_lowestHeap = h;
}

/* =========================
   Display helper
   ========================= */
#if DISPLAY_ENABLED
static void dispMsg(const char* l1, const char* l2 = "", const char* l3 = "") {
  g_display.clearDisplay();
  g_display.setTextSize(1);
  g_display.setCursor(0, 0);  g_display.println(l1);
  g_display.setCursor(0, 12); g_display.println(l2);
  g_display.setCursor(0, 24); g_display.println(l3);
  g_display.display();
}
#else
static void dispMsg(const char*, const char* = "", const char* = "") {}
#endif

/* =========================
   Temperature sampling
   ========================= */
static float readTemperatureAvg() {
  uint32_t sum = 0;

  for (uint8_t i = 0; i < ADC_SAMPLES; ++i) {
    sum += analogRead(THERM_PIN);

    if ((i & 0x0F) == 0x0F) {
      ESP.wdtFeed();
    }

    delayMicroseconds(200);
  }

  float avg = (float)sum / (float)ADC_SAMPLES;
  g_adcRaw = (uint16_t)(avg + 0.5f);

  float volt = avg * (3.3f / 1024.0f);
  if (volt >= 3.3f) volt = 3.29f;
  if (volt <= 0.01f) return g_temperature;

  float res = SERIES_RESISTOR * (3.3f / volt - 1.0f);
  if (res <= 0.0f) return g_temperature;

  float sh = logf(res / NOMINAL_RESISTANCE);
  sh /= B_COEFFICIENT;
  sh += 1.0f / (NOMINAL_TEMP + 273.15f);
  sh  = 1.0f / sh - 273.15f;

  if (sh < -40.0f || sh > 125.0f) {
    return g_temperature;
  }

  return sh + CAL_OFFSET;
}

static float applySpikeFilter(float raw) {
  float d = raw - g_temperature;
  if (d < 0) d = -d;

  if (d <= SPIKE_THRESHOLD_C) {
    g_spikeCnt = 0;
    return raw;
  }

  if (g_spikeCnt == 0) {
    g_spikeCand = raw;
    g_spikeCnt = 1;
  } else {
    float cd = raw - g_spikeCand;
    if (cd < 0) cd = -cd;

    if (cd < 2.0f) {
      ++g_spikeCnt;
      g_spikeCand = raw;
    } else {
      g_spikeCand = raw;
      g_spikeCnt = 1;
    }
  }

  if (g_spikeCnt >= SPIKE_PERSIST_COUNT) {
    g_spikeCnt = 0;
    return raw;
  }

  inc32(g_spikeRejects);
  return g_temperature;
}

/* =========================
   Wi-Fi state machine
   ========================= */
static void wifiStateMachine(unsigned long now) {
  switch (g_wifiState) {

    case WIFI_ST_DISCONNECTED: {
      WiFi.persistent(false);
      WiFi.mode(WIFI_STA);
      WiFi.setAutoReconnect(false);
      WiFi.setSleepMode(WIFI_NONE_SLEEP);

      WiFi.begin(g_ssid, g_pass);

      g_wifiConnectStart = now;
      g_wifiState = WIFI_ST_CONNECTING;

      dispMsg("WiFi connecting", g_ssid);
      Serial.printf("[%lums] WiFi: connecting to %s\n", now, g_ssid);
      break;
    }

    case WIFI_ST_CONNECTING: {
      wl_status_t st = WiFi.status();

      if (st == WL_CONNECTED) {
        g_wifiState = WIFI_ST_CONNECTED;
        g_wifiRetryDelay = WIFI_RETRY_BASE;
        g_lastError[0] = '\0';

        inc32(g_rtc.wifiReconnects);
        rtcSave();

        cacheNetworkInfo();
        g_sendSoon = true;

        Serial.printf("[%lums] WiFi: connected IP=%s RSSI=%d ch=%d BSSID=%s\n",
                      now, g_ipStr, WiFi.RSSI(), WiFi.channel(), g_bssidStr);

        dispMsg("WiFi OK", g_ipStr);
      }
      else if (now - g_wifiConnectStart > WIFI_CONNECT_TIMEOUT) {
        WiFi.disconnect();
        delay(50);
        yield();

        g_wifiState = WIFI_ST_FAILED;
        g_wifiFailTime = now;

        if (g_wifiRetryDelay < WIFI_RETRY_MAX) {
          g_wifiRetryDelay *= 2;
          if (g_wifiRetryDelay > WIFI_RETRY_MAX) g_wifiRetryDelay = WIFI_RETRY_MAX;
        }

        logErr("WiFi", "connect timeout");
        dispMsg("WiFi FAIL", "timeout");
      }
      break;
    }

    case WIFI_ST_CONNECTED: {
      if (WiFi.status() != WL_CONNECTED) {
        WiFi.disconnect();
        delay(50);
        yield();

        g_wifiState = WIFI_ST_FAILED;
        g_wifiFailTime = now;

        if (g_wifiRetryDelay < WIFI_RETRY_MAX) {
          g_wifiRetryDelay *= 2;
          if (g_wifiRetryDelay > WIFI_RETRY_MAX) g_wifiRetryDelay = WIFI_RETRY_MAX;
        }

        logErr("WiFi", "link lost");
        dispMsg("WiFi lost");
      }
      break;
    }

    case WIFI_ST_FAILED: {
      if (now - g_wifiFailTime >= g_wifiRetryDelay) {
        g_wifiState = WIFI_ST_DISCONNECTED;
      }
      break;
    }
  }
}

/* =========================
   HTTP response-body handling
   ========================= */
static void readResponseBody(HTTPClient& http) {
  g_respBuf[0] = '\0';

  WiFiClient* s = http.getStreamPtr();   // <-- correct for ESP8266
  if (!s) return;

  unsigned long start = millis();
  size_t n = 0;

  while ((millis() - start) < 1000UL && n < (sizeof(g_respBuf) - 1)) {
    int av = s->available();

    if (av > 0) {
      size_t room = sizeof(g_respBuf) - 1 - n;
      size_t want = ((size_t)av < room) ? (size_t)av : room;

      size_t rd = s->readBytes(g_respBuf + n, want);
      if (rd == 0) break;

      n += rd;
    }
    else if (!s->connected()) {
      break;
    }
    else {
      delay(2);
      ESP.wdtFeed();
    }
  }

  g_respBuf[n] = '\0';
}

static bool responseLooksOk() {
  if (g_respBuf[0] == '\0') return true;

  bool ok =
    strstr(g_respBuf, "ok") != nullptr ||
    strstr(g_respBuf, "success") != nullptr ||
    strstr(g_respBuf, "\"status\":\"ok\"") != nullptr ||
    strstr(g_respBuf, "\"result\":\"ok\"") != nullptr;

  if (ok) return true;

  if (strstr(g_respBuf, "error") != nullptr) {
    return false;
  }

  return true;
}

/* =========================
   Telemetry JSON builder
   ========================= */
static size_t buildTelemetry() {
  sanitize(g_lastError);
  sanitize(g_resetReason);
  cacheNetworkInfo();

  int n = snprintf(
    g_payload,
    sizeof(g_payload),

    "{"
    "\"temperature\":%.2f,"
    "\"rssi\":%d,"
    "\"boot_count\":%lu,"
    "\"fails\":%lu,"

    "\"schema\":1,"
    "\"device\":\"%s\","
    "\"fw\":\"%s\","
    "\"sdk\":\"%s\","
    "\"chip_id\":\"%06X\","
    "\"mac\":\"%s\","
    "\"ip\":\"%s\","

    "\"uptime_s\":%lu,"
    "\"millis\":%lu,"

    "\"temp_c\":%.2f,"
    "\"adc_raw\":%u,"
    "\"adc_samples\":%u,"
    "\"spike_rejects\":%lu,"

    "\"channel\":%d,"
    "\"bssid\":\"%s\","
    "\"wifi_state\":\"%s\","
    "\"wifi_reconnects\":%lu,"

    "\"consec_fails\":%lu,"
    "\"total_uploads\":%lu,"
    "\"ok_uploads\":%lu,"
    "\"fail_uploads\":%lu,"
    "\"ota_updates\":%lu,"

    "\"heap\":%u,"
    "\"heap_min\":%lu,"

    "\"reset_reason\":\"%s\","
    "\"last_error\":\"%s\""
    "}",

    g_temperature,
    WiFi.RSSI(),
    (unsigned long)g_rtc.bootCount,
    (unsigned long)g_consecFails,

    FW_HOSTNAME,
    FW_VERSION,
    g_sdk,
    (unsigned)ESP.getChipId(),
    g_macStr,
    g_ipStr,

    (unsigned long)(millis() / 1000UL),
    (unsigned long)millis(),

    g_temperature,
    (unsigned)g_adcRaw,
    (unsigned)ADC_SAMPLES,
    (unsigned long)g_spikeRejects,

    WiFi.channel(),
    g_bssidStr,
    WIFI_STATE_STR[g_wifiState],
    (unsigned long)g_rtc.wifiReconnects,

    (unsigned long)g_consecFails,
    (unsigned long)g_rtc.totalUploads,
    (unsigned long)g_rtc.okUploads,
    (unsigned long)g_rtc.failUploads,
    (unsigned long)g_rtc.otaUpdates,

    ESP.getFreeHeap(),
    (unsigned long)g_lowestHeap,

    g_resetReason,
    g_lastError
  );

  if (n < 0 || (size_t)n >= sizeof(g_payload)) {
    logErr("JSON", "payload truncated");
    return 0;
  }

  return (size_t)n;
}

/* =========================
   HTTP upload
   ========================= */
static bool sendToServer() {
  if (WiFi.status() != WL_CONNECTED) {
    logErr("HTTP", "wifi down");
    return false;
  }

  size_t len = buildTelemetry();
  bool success = false;

  if (len == 0) {
    logErr("HTTP", "payload build failed");
  }
  else {
    strcpy_P(g_urlBuf, SERVER_URL);

#if USE_HTTPS
    BearSSL::WiFiClientSecure client;
    client.setFingerprint(TLS_FINGERPRINT);
#else
    WiFiClient client;
#endif

    client.setTimeout(SERVER_TIMEOUT_MS / 1000UL);

    HTTPClient http;
    http.setReuse(false);

    if (!http.begin(client, g_urlBuf)) {
      logErr("HTTP", "begin failed");
    }
    else {
      http.addHeader(F("Content-Type"), F("application/json"));

      ESP.wdtFeed();

      int httpCode = http.POST((uint8_t*)g_payload, len);

      readResponseBody(http);
      http.end();

      ESP.wdtFeed();

      if (httpCode == HTTP_CODE_OK) {
        if (responseLooksOk()) {
          success = true;
        } else {
          sanitize(g_respBuf);
          logErr("HTTP body", g_respBuf);
        }
      } else {
        char msg[32];
        if (httpCode > 0) snprintf(msg, sizeof(msg), "HTTP %d", httpCode);
        else snprintf(msg, sizeof(msg), "transport %d", httpCode);

        logErr("HTTP", msg);
      }
    }
  }

  inc32(g_rtc.totalUploads);

  if (success) {
    g_consecFails = 0;
    g_uploadRetryDelay = UPLOAD_RETRY_BASE;
    g_lastError[0] = '\0';
    g_lastSuccessTime = millis();

    inc32(g_rtc.okUploads);

    if ((g_rtc.okUploads % RTC_SAVE_EVERY_UPLOADS) == 0) {
      rtcSave();
    }

    dispMsg("Send OK");
    Serial.printf("[%lums] HTTP: 200 OK\n", millis());
  }
  else {
    inc32(g_consecFails);
    inc32(g_rtc.failUploads);

    if (g_uploadRetryDelay < UPLOAD_RETRY_MAX) {
      g_uploadRetryDelay *= 2;
      if (g_uploadRetryDelay > UPLOAD_RETRY_MAX) {
        g_uploadRetryDelay = UPLOAD_RETRY_MAX;
      }
    }

    dispMsg("Send FAIL");

    if (g_consecFails >= MAX_CONSECUTIVE_FAILURES) {
      safeRestart("max consecutive upload failures");
    }
  }

  return success;
}

/* =========================
   Raw HTTP response helpers
   -------------------------
   These avoid String-based responses.
   ========================= */
static void sendRaw(const char* status, const char* ctype, const char* body, size_t len) {
  WiFiClient& c = g_server.client();

  c.print(F("HTTP/1.1 "));
  c.print(status);
  c.print(F("\r\nContent-Type: "));
  c.print(ctype);
  c.print(F("\r\nContent-Length: "));
  c.print((unsigned long)len);
  c.print(F("\r\nConnection: close\r\n\r\n"));

  if (body && len) {
    c.write((const uint8_t*)body, len);
  }

  c.flush();
  c.stop();
}

static void sendRedirect(const char* loc) {
  WiFiClient& c = g_server.client();

  c.print(F("HTTP/1.1 303 See Other\r\nLocation: "));
  c.print(loc);
  c.print(F("\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"));

  c.flush();
  c.stop();
}

/* =========================
   HTML builder helpers
   ========================= */
static void htmlClear() {
  g_htmlLen = 0;
  g_html[0] = '\0';
}

static void htmlAppendf(const char* fmt, ...) {
  if (g_htmlLen >= (sizeof(g_html) - 1)) return;

  va_list ap;
  va_start(ap, fmt);

  int n = vsnprintf(g_html + g_htmlLen, sizeof(g_html) - g_htmlLen, fmt, ap);

  va_end(ap);

  if (n > 0) {
    size_t room = sizeof(g_html) - g_htmlLen - 1;
    size_t add = ((size_t)n > room) ? room : (size_t)n;
    g_htmlLen += add;
  }
}

static void htmlAppend(const char* s) {
  htmlAppendf("%s", s);
}

/* =========================
   Web handlers
   ========================= */
static void handleRoot() {
  cacheNetworkInfo();

  unsigned long now = millis();

  char up[32];
  unsigned long s = now / 1000UL;
  snprintf(up, sizeof(up), "%lud %02luh %02lum %02lus",
           s / 86400UL,
           (s / 3600UL) % 24UL,
           (s / 60UL) % 60UL,
           s % 60UL);

  char lastUp[32];
  if (g_lastSuccessTime == 0) {
    strcpy(lastUp, "never");
  } else {
    snprintf(lastUp, sizeof(lastUp), "%lus ago",
             (now - g_lastSuccessTime) / 1000UL);
  }

  unsigned long interval = (g_consecFails == 0) ? SEND_INTERVAL_BASE : g_uploadRetryDelay;
  unsigned long elapsed = now - g_lastSendTime;
  unsigned long remain = (elapsed >= interval) ? 0 : (interval - elapsed);

  char next[40];
  snprintf(next, sizeof(next), "%lus%s",
           remain / 1000UL,
           g_consecFails ? " (backoff)" : "");

  htmlClear();

  htmlAppend(
    "<!DOCTYPE html><html><head>"
    "<meta charset='utf-8'>"
    "<meta name='viewport' content='width=device-width,initial-scale=1'>"
    "<meta http-equiv='refresh' content='10'>"
    "<title>Temp Monitor</title>"
    "<style>"
    "body{font-family:Arial;background:#111;color:#eee;padding:16px;margin:0}"
    "h1{color:#4CAF50}"
    ".c{background:#222;border-left:4px solid #4CAF50;border-radius:8px;padding:12px;margin:10px 0}"
    ".v{font-size:32px;font-weight:bold;color:#4CAF50;margin:4px 0}"
    ".s{font-size:14px;color:#bbb;margin:3px 0}"
    "button{background:#4CAF50;color:#fff;border:0;padding:12px 20px;border-radius:6px;margin:4px;font-size:14px}"
    ".d{background:#c62828}"
    "</style></head><body>"
    "<h1>Temp Monitor</h1>"
  );

  htmlAppendf(
    "<div class='c'><div class='s'>Temperature</div>"
    "<div class='v'>%.1f&#176;C</div>"
    "<div class='s'>ADC raw: %u | samples: %u | spikes rejected: %lu</div>"
    "</div>",
    g_temperature,
    (unsigned)g_adcRaw,
    (unsigned)ADC_SAMPLES,
    (unsigned long)g_spikeRejects
  );

  htmlAppendf(
    "<div class='c'><div class='s'>System</div>"
    "<div class='s'>FW: %s | SDK: %s</div>"
    "<div class='s'>Chip: %06X | CPU: %u MHz</div>"
    "<div class='s'>Flash: %u bytes @ %u Hz</div>"
    "<div class='s'>Heap: %u | Lowest: %lu</div>"
    "<div class='s'>Uptime: %s</div>"
    "<div class='s'>Reset: %s</div>"
    "</div>",
    FW_VERSION,
    g_sdk,
    (unsigned)ESP.getChipId(),
    (unsigned)ESP.getCpuFreqMHz(),
    ESP.getFlashChipRealSize(),
    ESP.getFlashChipSpeed(),
    ESP.getFreeHeap(),
    (unsigned long)g_lowestHeap,
    up,
    g_resetReason
  );

  htmlAppendf(
    "<div class='c'><div class='s'>Network</div>"
    "<div class='s'>WiFi: %s | State: %s</div>"
    "<div class='s'>IP: %s | MAC: %s</div>"
    "<div class='s'>RSSI: %d dBm | Channel: %d</div>"
    "<div class='s'>BSSID: %s</div>"
    "<div class='s'>WiFi reconnects: %lu</div>"
    "<div class='s'>Last upload: %s | Next: %s</div>"
    "</div>",
    (WiFi.status() == WL_CONNECTED) ? "Connected" : "Offline",
    WIFI_STATE_STR[g_wifiState],
    g_ipStr,
    g_macStr,
    WiFi.RSSI(),
    WiFi.channel(),
    g_bssidStr,
    (unsigned long)g_rtc.wifiReconnects,
    lastUp,
    next
  );

  htmlAppendf(
    "<div class='c'><div class='s'>Uploads</div>"
    "<div class='s'>Boot: %lu | OTA updates: %lu</div>"
    "<div class='s'>Consec fails: %lu | Total fails: %lu</div>"
    "<div class='s'>OK: %lu | Total: %lu</div>"
    "</div>",
    (unsigned long)g_rtc.bootCount,
    (unsigned long)g_rtc.otaUpdates,
    (unsigned long)g_consecFails,
    (unsigned long)g_rtc.failUploads,
    (unsigned long)g_rtc.okUploads,
    (unsigned long)g_rtc.totalUploads
  );

  if (g_lastError[0] != '\0') {
    htmlAppendf(
      "<div class='c' style='border-left-color:#c62828'><div class='s'>Last error</div>"
      "<div class='s'>%s</div></div>",
      g_lastError
    );
  }

  htmlAppend(
    "<div class='c'>"
    "<button onclick='location.href=\"/send\"'>Send Now</button>"
    "<button class='d' onclick='if(confirm(\"Reboot?\"))location.href=\"/reset\"'>Reboot</button>"
    "</div>"
  );

  htmlAppend("</body></html>");

  sendRaw("200 OK", "text/html", g_html, g_htmlLen);
}

static void handleSend() {
  g_sendNow = true;
  sendRedirect("/");
}

static void handleTemp() {
  size_t len = buildTelemetry();

  if (len == 0) {
    sendRaw("500 Internal Server Error", "text/plain",
            "telemetry build failed", sizeof("telemetry build failed") - 1);
    return;
  }

  sendRaw("200 OK", "application/json", g_payload, len);
}

static void handleReset() {
  sendRaw("200 OK", "text/plain", "Rebooting...", sizeof("Rebooting...") - 1);
  g_rebootNow = true;
}

static void handleNotFound() {
  sendRaw("404 Not Found", "text/plain", "Not Found", sizeof("Not Found") - 1);
}

/* =========================
   OTA
   ========================= */
static void setupOTA() {
  ArduinoOTA.setHostname(FW_HOSTNAME);

  /*
    OTA password intentionally removed.
    This is simpler, but only safe on a trusted network.
  */

  ArduinoOTA.onStart([]() {
    Serial.println(F("[OTA] start"));
    dispMsg("OTA Start");
  });

  ArduinoOTA.onEnd([]() {
    inc32(g_rtc.otaUpdates);
    rtcSave();

    Serial.println(F("[OTA] complete"));
    dispMsg("OTA Done");
  });

  ArduinoOTA.onProgress([](unsigned int, unsigned int) {
    ESP.wdtFeed();
  });

  ArduinoOTA.onError([](ota_error_t e) {
    char msg[32];
    snprintf(msg, sizeof(msg), "OTA error %u", (unsigned)e);
    logErr("OTA", msg);
    dispMsg("OTA Error", msg);
  });

  ArduinoOTA.begin();
  logInfo("OTA", "ready");
}

/* =========================
   Setup
   ========================= */
void setup() {
  Serial.begin(115200);
  delay(50);

  Serial.println(F("\n=== " FW_VERSION " BOOT ==="));

  g_bootTime = millis();
  g_lowestHeap = ESP.getFreeHeap();

  cacheResetReason();
  cacheSdk();

  rtcLoad();

  strcpy_P(g_ssid, WIFI_SSID_P);
  strcpy_P(g_pass, WIFI_PASSWORD_P);

  Serial.printf("Boot RTC #%lu | WiFi reconn %lu\n",
                (unsigned long)g_rtc.bootCount,
                (unsigned long)g_rtc.wifiReconnects);
  Serial.printf("Reset reason: %s\n", g_resetReason);

#if DISPLAY_ENABLED
  Wire.begin(OLED_SDA, OLED_SCL);
  if (!g_display.begin(SSD1306_SWITCHCAPVCC, OLED_I2C_ADDR)) {
    logErr("OLED", "begin failed - continuing headless");
  } else {
    g_display.setTextColor(WHITE);
    dispMsg("Booting", FW_VERSION);
  }
#endif

  g_temperature = readTemperatureAvg();
  g_spikeCand = g_temperature;

  WiFi.persistent(false);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(false);
  WiFi.setSleepMode(WIFI_NONE_SLEEP);

  cacheNetworkInfo();

  g_wifiState = WIFI_ST_DISCONNECTED;
  g_wifiRetryDelay = WIFI_RETRY_BASE;
  g_uploadRetryDelay = UPLOAD_RETRY_BASE;

  if (!MDNS.begin(FW_HOSTNAME)) {
    logErr("mDNS", "begin failed");
  } else {
    logInfo("mDNS", "started");
  }

  g_server.on("/", handleRoot);
  g_server.on("/send", handleSend);
  g_server.on("/temp", handleTemp);
  g_server.on("/reset", handleReset);
  g_server.onNotFound(handleNotFound);
  g_server.begin();

  logInfo("HTTP", "server on :80");

  setupOTA();

  unsigned long now = millis();
  g_lastSendTime = now;
  g_lastTempRead = now;

  rtcSave();
}

/* =========================
   Main loop
   ========================= */
void loop() {
  ArduinoOTA.handle();
  g_server.handleClient();
  MDNS.update();

  unsigned long now = millis();

  wifiStateMachine(now);

  trackHeap();

  if (ESP.getFreeHeap() < LOW_HEAP_THRESHOLD) {
    safeRestart("low heap");
  }

  if (now - g_bootTime >= REBOOT_INTERVAL) {
    sendToServer();
    safeRestart("scheduled 24h reboot");
  }

  if (now - g_lastTempRead >= TEMP_READ_INTERVAL) {
    g_lastTempRead = now;
    float raw = readTemperatureAvg();
    g_temperature = applySpikeFilter(raw);
  }

  if (g_rebootNow) {
    safeRestart("user reboot");
  }

  if (g_sendNow) {
    g_sendNow = false;
    g_sendSoon = false;
    g_lastSendTime = now;

    if (WiFi.status() == WL_CONNECTED) {
      sendToServer();
    } else {
      logErr("SendNow", "wifi down");
    }
  }

  if (g_sendSoon) {
    g_sendSoon = false;

    if (WiFi.status() == WL_CONNECTED && g_consecFails == 0) {
      g_lastSendTime = now;
      sendToServer();
    }
  }

  unsigned long interval = (g_consecFails == 0) ? SEND_INTERVAL_BASE : g_uploadRetryDelay;

  if (now - g_lastSendTime >= interval) {
    g_lastSendTime = now;

    if (WiFi.status() == WL_CONNECTED) {
      sendToServer();
    } else {
      logErr("Upload", "wifi down");
    }
  }

  delay(LOOP_YIELD_MS);
  ESP.wdtFeed();
}