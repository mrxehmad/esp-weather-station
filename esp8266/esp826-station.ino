#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <ESP8266mDNS.h>
#include <ArduinoOTA.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <time.h>

// ========================================
// CONFIGURATION — edit these
// ========================================
const char* WIFI_SSID     = "OpenWrt";
const char* WIFI_PASSWORD = "123123123";

static const char SERVER_URL[] PROGMEM = "http://temp.ehmi.se/api/receive.php";

const unsigned long SEND_INTERVAL = 10UL * 60UL * 1000UL; // 10 minutes

// ========================================
// HARDWARE
// ========================================
#define OLED_RESET -1
#define OLED_SDA   14
#define OLED_SCL   12
Adafruit_SSD1306 display(128, 64, &Wire, OLED_RESET);

const int   THERM_PIN          = A0;
const float SERIES_RESISTOR    = 10000.0f;
const float NOMINAL_RESISTANCE = 10000.0f;
const float NOMINAL_TEMP       = 25.0f;
const float B_COEFFICIENT      = 3425.0f;

// ========================================
// GLOBALS
// ========================================
float         currentTemp  = 0.0f;
bool          timeSynced   = false;
unsigned long lastSendTime = 0;
unsigned long lastTempRead = 0;

String lastError;
bool   lastWriteOk             = false;
unsigned long lastWriteAttempt = 0;
unsigned long lastWriteOkTime  = 0;

ESP8266WebServer server(80);

// ========================================
// TEMPERATURE
// ========================================
float readTemperature() {
  int   raw  = analogRead(THERM_PIN);
  float volt = raw * (3.3f / 1024.0f);
  if (volt >= 3.3f) volt = 3.29f;
  float res = SERIES_RESISTOR * (3.3f / volt - 1.0f);
  float s   = log(res / NOMINAL_RESISTANCE);
  s /= B_COEFFICIENT;
  s += 1.0f / (NOMINAL_TEMP + 273.15f);
  s  = 1.0f / s - 273.15f;
  if (s < -40.0f || s > 85.0f) return currentTemp;
  return s;
}

// ========================================
// DISPLAY
// ========================================
void dispMsg(const char* l1, const char* l2 = "", const char* l3 = "") {
  display.clearDisplay();
  display.setTextSize(1);
  display.setCursor(0, 0);  display.println(l1);
  display.setCursor(0, 12); display.println(l2);
  display.setCursor(0, 24); display.println(l3);
  display.display();
}

void updateDisplay() {
  display.clearDisplay();

  // Row 0: WiFi status
  display.setTextSize(1);
  display.setCursor(0, 0);
  if (WiFi.status() == WL_CONNECTED) {
    display.print(F("WiFi OK  RSSI:"));
    display.println(WiFi.RSSI());
  } else {
    display.println(F("WiFi: OFFLINE"));
  }

  // Big temperature
  display.setTextSize(3);
  display.setCursor(10, 18);
  display.print(currentTemp, 1);
  display.setTextSize(1); display.setCursor(100, 20); display.print('o');
  display.setTextSize(2); display.setCursor(105, 25); display.print('C');

  // Next send countdown
  display.setTextSize(1);
  display.setCursor(0, 48);
  if (timeSynced) {
    long secLeft = ((long)SEND_INTERVAL - (long)(millis() - lastSendTime)) / 1000L;
    if (secLeft < 0) secLeft = 0;
    display.print(F("Next: "));
    if (secLeft >= 60) { display.print(secLeft/60); display.print(F("m")); }
    else               { display.print(secLeft);    display.print(F("s")); }
  } else {
    display.print(F("Waiting NTP..."));
  }

  // Last write result
  display.setCursor(0, 57);
  display.print(lastWriteOk ? F("Send: OK") : F("Send: --"));
  display.display();
}

// ========================================
// NTP
// ========================================
void syncTime() {
  dispMsg("Syncing time", "NTP...");
  configTime(0, 0, "pool.ntp.org", "time.nist.gov");
  time_t now = time(nullptr);
  for (int i = 0; i < 20 && now < 1000000000UL; i++) {
    delay(500);
    now = time(nullptr);
  }
  timeSynced = (now > 1000000000UL);
  if (timeSynced) {
    char buf[24];
    struct tm* t = localtime(&now);
    strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M", t);
    dispMsg("Time synced!", buf);
  } else {
    dispMsg("NTP failed", "Will retry...");
  }
  delay(1000);
}

// ========================================
// WIFI
// ========================================
void connectWiFi() {
  dispMsg("Connecting WiFi", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.persistent(false);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  for (int i = 0; i < 40 && WiFi.status() != WL_CONNECTED; i++) delay(500);

  if (WiFi.status() == WL_CONNECTED) {
    String ip = WiFi.localIP().toString();
    dispMsg("WiFi connected!", ip.c_str());
    delay(1000);
    syncTime();
  } else {
    dispMsg("WiFi FAILED", "Retrying in loop");
    delay(1000);
  }
}

void checkWiFi() {
  if (WiFi.status() != WL_CONNECTED) {
    WiFi.reconnect();
    for (int i = 0; i < 20 && WiFi.status() != WL_CONNECTED; i++) delay(500);
    if (WiFi.status() == WL_CONNECTED && !timeSynced) syncTime();
  }
}

// ========================================
// SEND DATA
// ========================================
bool sendToServer() {
  if (WiFi.status() != WL_CONNECTED) {
    lastError = F("WiFi disconnected");
    return false;
  }

  char url[60];
  strcpy_P(url, SERVER_URL);

  WiFiClient client;
  HTTPClient http;
  http.begin(client, url);
  http.addHeader(F("Content-Type"), F("application/json"));
  http.setTimeout(10000);

  // Build JSON payload: {"temperature": XX.XX}
  String payload = F("{\"temperature\":");
  payload += String(currentTemp, 2);
  payload += '}';

  lastWriteAttempt = millis();
  int httpCode = http.POST(payload);
  http.end();

  if (httpCode == 200) {
    lastWriteOk    = true;
    lastWriteOkTime = millis();
    lastError      = "";
    return true;
  } else {
    lastWriteOk = false;
    lastError   = String(F("HTTP ")) + String(httpCode);
    return false;
  }
}

// ========================================
// DEBUG PAGE
// ========================================
static String htmlEsc(const String& s) {
  String o; o.reserve(s.length() + 8);
  for (size_t i = 0; i < s.length(); i++) {
    char c = s[i];
    if      (c=='&') o += F("&amp;");
    else if (c=='<') o += F("&lt;");
    else if (c=='>') o += F("&gt;");
    else             o += c;
  }
  return o;
}

void handleDebug() {
  String writeResult = F("not requested");
  if (server.hasArg(F("write")) && server.arg(F("write")) == F("1")) {
    writeResult = sendToServer() ? F("OK") : (String(F("FAILED: ")) + lastError);
  }

  char timeBuf[24] = "not synced";
  if (timeSynced) {
    time_t now = time(nullptr);
    struct tm* t = localtime(&now);
    strftime(timeBuf, sizeof(timeBuf), "%Y-%m-%d %H:%M:%S", t);
  }

  long secLeft = ((long)SEND_INTERVAL - (long)(millis() - lastSendTime)) / 1000L;
  if (secLeft < 0) secLeft = 0;

  String h; h.reserve(2000);
  h = F("<!doctype html><html><head>"
        "<meta charset='utf-8'>"
        "<meta name='viewport' content='width=device-width,initial-scale=1'>"
        "<meta http-equiv='refresh' content='10'>"
        "<title>Temp Sensor</title>"
        "<style>"
        "body{font-family:monospace;background:#0d1117;color:#c9d1d9;padding:16px;margin:0}"
        "h2{color:#58a6ff;margin:16px 0 6px;font-size:15px}"
        "table{border-collapse:collapse;width:100%;max-width:560px}"
        "td{padding:5px 10px;border-bottom:1px solid #21262d;font-size:14px}"
        "td:first-child{color:#8b949e;width:170px}"
        ".ok{color:#3fb950}.fail{color:#f85149}"
        "pre{background:#161b22;border:1px solid #30363d;border-radius:6px;"
        "padding:10px;font-size:12px;word-break:break-all;max-width:560px}"
        ".btn{display:inline-block;padding:9px 18px;border-radius:6px;"
        "font-size:14px;text-decoration:none;color:#fff;margin:4px}"
        "</style></head><body>"
        "<h2>&#127777; ESP Temp Sensor — Debug</h2>");

  h += F("<table>");
  h += F("<tr><td>Chip</td><td>"); h += String(ESP.getChipId(),HEX); h += F("</td></tr>");
  h += F("<tr><td>Uptime</td><td>"); h += String(millis()/1000); h += F("s</td></tr>");
  h += F("<tr><td>Free heap</td><td>"); h += String(ESP.getFreeHeap()); h += F(" B</td></tr>");
  h += F("<tr><td>Temperature</td><td><b>"); h += String(currentTemp,2); h += F(" °C</b></td></tr>");
  h += F("<tr><td>NTP synced</td><td class='"); h += timeSynced?F("ok'>YES"):F("fail'>NO"); h += F("</td></tr>");
  h += F("<tr><td>Current time</td><td>"); h += timeBuf; h += F("</td></tr>");
  h += F("<tr><td>WiFi</td><td class='");
  h += (WiFi.status()==WL_CONNECTED)?F("ok'>CONNECTED"):F("fail'>DISCONNECTED");
  h += F("</td></tr>");
  h += F("<tr><td>RSSI</td><td>"); h += String(WiFi.RSSI()); h += F(" dBm</td></tr>");
  h += F("<tr><td>Last send</td><td class='");
  h += lastWriteOk?F("ok'>OK"):F("fail'>FAILED");
  h += F("</td></tr>");
  h += F("<tr><td>Next auto send</td><td>"); h += String(secLeft); h += F("s</td></tr>");
  h += F("<tr><td>On-demand result</td><td>"); h += htmlEsc(writeResult); h += F("</td></tr>");
  h += F("</table>");

  h += F("<tr><td>Server</td><td>temp.ehmi.se</td></tr>");

  h += F("<h2>Last Error</h2><pre class='fail'>");
  h += htmlEsc(lastError.length() ? lastError : String(F("none")));
  h += F("</pre>");

  h += F("<a class='btn' style='background:#238636' href='/debug?write=1'>&#9654; Send Now</a>");
  h += F("<a class='btn' style='background:#1f6feb' href='/debug'>&#8635; Refresh</a>");
  h += F("<p style='color:#484f58;font-size:11px;margin-top:12px'>Auto-refreshes every 10s</p>"
         "</body></html>");

  server.send(200, F("text/html"), h);
}

void handleTemp() {
  String j = F("{\"temperature_c\":");
  j += String(currentTemp,2);
  j += F(",\"wifi\":\"");
  j += (WiFi.status()==WL_CONNECTED)?F("connected"):F("disconnected");
  j += F("\",\"time_synced\":");
  j += timeSynced?F("true"):F("false");
  j += F(",\"last_write_ok\":");
  j += lastWriteOk?F("true"):F("false");
  j += '}';
  server.send(200, F("application/json"), j);
}

// ========================================
// OTA
// ========================================
void setupOTA() {
  ArduinoOTA.setHostname("TempMonitor");
  ArduinoOTA.setPassword("admin123");
  ArduinoOTA.onStart([]()   { dispMsg("OTA Update","Starting..."); });
  ArduinoOTA.onEnd([]()     { dispMsg("OTA Done!","Rebooting..."); });
  ArduinoOTA.onProgress([](unsigned int p, unsigned int t) {
    unsigned int pct = p/(t/100);
    display.clearDisplay();
    display.setTextSize(1); display.setCursor(0,0); display.println(F("OTA Update"));
    display.setTextSize(2); display.setCursor(30,20); display.print(pct); display.println('%');
    display.drawRect(10,45,108,10,WHITE);
    display.fillRect(12,47,(pct*104)/100,6,WHITE);
    display.display();
  });
  ArduinoOTA.onError([](ota_error_t e) {
    const char* m = "OTA Error";
    switch(e){
      case OTA_AUTH_ERROR:    m="Auth Failed";    break;
      case OTA_BEGIN_ERROR:   m="Begin Failed";   break;
      case OTA_CONNECT_ERROR: m="Connect Failed"; break;
      case OTA_RECEIVE_ERROR: m="Receive Failed"; break;
      case OTA_END_ERROR:     m="End Failed";     break;
    }
    dispMsg("OTA Error!", m);
  });
  ArduinoOTA.begin();
}

// ========================================
// SETUP
// ========================================
void setup() {
  Wire.begin(OLED_SDA, OLED_SCL);
  if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) { for(;;); }
  dispMsg("Temp Monitor v5.0", "Booting...");
  delay(1000);

  connectWiFi();

  MDNS.begin("tempmonitor"); // http://tempmonitor.local

  server.on(F("/"),      HTTP_GET, handleDebug);
  server.on(F("/debug"), HTTP_GET, handleDebug);
  server.on(F("/temp"),  HTTP_GET, handleTemp);
  server.begin();

  setupOTA();

  currentTemp  = readTemperature();
  dispMsg("Ready!", "temp.ehmi.se", "visit /debug");
  delay(1500);
}

// ========================================
// LOOP
// ========================================
void loop() {
  ArduinoOTA.handle();
  server.handleClient();
  MDNS.update();

  unsigned long now = millis();

  // Read temp every second
  if (now - lastTempRead >= 1000) {
    lastTempRead = now;
    currentTemp  = readTemperature();
    updateDisplay();
  }

  // WiFi watchdog every 30s
  static unsigned long lastWifiCheck = 0;
  if (now - lastWifiCheck >= 30000) {
    lastWifiCheck = now;
    checkWiFi();
  }

  // Send to server every 10 minutes
  if (now - lastSendTime >= SEND_INTERVAL) {
    lastSendTime = now;
    sendToServer();
  }

  delay(10);
}
