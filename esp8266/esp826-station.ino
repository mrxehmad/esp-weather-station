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
#include <ArduinoJson.h>

// ========================================
// CONFIGURATION
// ========================================
const char* WIFI_SSID     = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";

static const char SERVER_URL[] PROGMEM = "http://[Your_EndPoint]/api/receive.php";

// Send interval (10 minutes)
const unsigned long SEND_INTERVAL = 10UL * 60UL * 1000UL;

// Display control (false = saves 10-15mA)
const bool DISPLAY_ENABLED = false;

// WiFi connection timeout (30 seconds)
const unsigned long WIFI_TIMEOUT = 30000;

// Server request timeout (15 seconds)
const unsigned long SERVER_TIMEOUT = 15000;

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
float currentTemp = 0.0f;
bool updateMode = false; // Default: sleep mode (power saving)
String lastError = "";
uint32_t bootCount = 0;
uint32_t consecutiveFails = 0;

unsigned long lastSend = 0;
unsigned long lastTempUpdate = 0;
unsigned long sleepStartTime = 0;
bool inModemSleep = false;

ESP8266WebServer server(80);

// ========================================
// TEMPERATURE READING
// ========================================
float readTemperature() {
  int raw = analogRead(THERM_PIN);
  float volt = raw * (3.3f / 1024.0f);
  if (volt >= 3.3f) volt = 3.29f;
  
  float res = SERIES_RESISTOR * (3.3f / volt - 1.0f);
  float steinhart = log(res / NOMINAL_RESISTANCE);
  steinhart /= B_COEFFICIENT;
  steinhart += 1.0f / (NOMINAL_TEMP + 273.15f);
  steinhart = 1.0f / steinhart - 273.15f;
  
  // Sanity check
  if (steinhart < -40.0f || steinhart > 85.0f) {
    return currentTemp; // Keep previous value
  }
  return steinhart;
}

// ========================================
// DISPLAY (only if enabled)
// ========================================
void dispMsg(const char* l1, const char* l2 = "", const char* l3 = "") {
  if (!DISPLAY_ENABLED) return;
  display.clearDisplay();
  display.setTextSize(1);
  display.setCursor(0, 0);  display.println(l1);
  display.setCursor(0, 12); display.println(l2);
  display.setCursor(0, 24); display.println(l3);
  display.display();
}

// ========================================
// WIFI CONNECTION
// ========================================
bool connectWiFi() {
  dispMsg("WiFi Connect", WIFI_SSID);
  
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(false);
  WiFi.persistent(false);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < WIFI_TIMEOUT) {
    delay(100);
  }
  
  bool connected = (WiFi.status() == WL_CONNECTED);
  
  if (connected) {
    dispMsg("WiFi OK", WiFi.localIP().toString().c_str());
  } else {
    dispMsg("WiFi FAIL");
    lastError = "WiFi timeout";
  }
  
  delay(500);
  return connected;
}

// ========================================
// SEND DATA TO SERVER
// ========================================
bool sendToServer() {
  if (WiFi.status() != WL_CONNECTED) {
    lastError = "WiFi down";
    return false;
  }
  
  dispMsg("Sending data...", String(currentTemp, 1).c_str());
  
  char url[60];
  strcpy_P(url, SERVER_URL);
  
  WiFiClient client;
  HTTPClient http;
  http.begin(client, url);
  http.addHeader(F("Content-Type"), F("application/json"));
  http.setTimeout(SERVER_TIMEOUT);
  
  // Build payload
  String payload = "{\"temperature\":";
  payload += String(currentTemp, 2);
  payload += ",\"rssi\":";
  payload += String(WiFi.RSSI());
  payload += ",\"boot_count\":";
  payload += String(bootCount);
  payload += ",\"fails\":";
  payload += String(consecutiveFails);
  payload += "}";
  
  int httpCode = http.POST(payload);
  
  if (httpCode == 200) {
    String response = http.getString();
    http.end();
    
    // Parse server response for update_mode
    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, response);
    
    if (!err && doc.containsKey("update_mode")) {
      updateMode = doc["update_mode"].as<bool>();
    } else {
      // No update_mode in response = default to sleep (power saving)
      updateMode = false;
    }
    
    consecutiveFails = 0; // Reset on success
    lastError = "";
    
    dispMsg("Send OK", updateMode ? "Mode: AWAKE" : "Mode: SLEEP");
    delay(1000);
    return true;
    
  } else {
    http.end();
    consecutiveFails++;
    lastError = "HTTP " + String(httpCode);
    
    // Stay awake if too many failures (safety)
    if (consecutiveFails >= 3) {
      updateMode = true;
    } else {
      updateMode = false; // Still try to sleep to save power
    }
    
    dispMsg("Send FAIL", lastError.c_str());
    delay(1000);
    return false;
  }
}

// ========================================
// MODEM SLEEP (WiFi OFF)
// ========================================
void enterModemSleep() {
  dispMsg("Modem Sleep", "10 min", "WiFi OFF");
  delay(2000);
  Serial.println("Entering modem sleep (WiFi OFF)...");
  
  // Disconnect and turn off WiFi
  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);
  WiFi.forceSleepBegin();
  delay(1);
  
  if (DISPLAY_ENABLED) {
    display.ssd1306_command(SSD1306_DISPLAYOFF);
  }
  
  inModemSleep = true;
  sleepStartTime = millis();
  
  Serial.println("WiFi modem disabled - CPU still running");
}

void exitModemSleep() {
  Serial.println("Waking from modem sleep...");
  
  // Wake up WiFi
  WiFi.forceSleepWake();
  delay(1);
  
  if (DISPLAY_ENABLED) {
    display.ssd1306_command(SSD1306_DISPLAYON);
  }
  
  inModemSleep = false;
  
  dispMsg("Waking up", "Connecting...");
  delay(500);
}

// ========================================
// WEB SERVER HANDLERS
// ========================================
void handleRoot() {
  String html = F("<!DOCTYPE html><html><head>"
    "<meta name='viewport' content='width=device-width,initial-scale=1'>"
    "<meta http-equiv='refresh' content='10'>"
    "<title>Temp Monitor</title>"
    "<style>"
    "body{font-family:Arial;background:#1a1a1a;color:#eee;padding:20px;margin:0}"
    "h1{color:#4CAF50;font-size:24px}"
    ".card{background:#2a2a2a;border-radius:8px;padding:15px;margin:10px 0;border-left:4px solid #4CAF50}"
    ".label{color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1px}"
    ".value{font-size:28px;font-weight:bold;color:#4CAF50;margin:5px 0}"
    ".info{font-size:14px;margin:5px 0}"
    ".status{display:inline-block;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:bold}"
    ".ok{background:#2e7d32;color:#fff}"
    ".warn{background:#f57c00;color:#fff}"
    ".fail{background:#c62828;color:#fff}"
    "button{background:#4CAF50;color:#fff;border:none;padding:12px 24px;border-radius:6px;cursor:pointer;font-size:14px;margin:5px}"
    "button:hover{background:#45a049}"
    "</style></head><body>"
    "<h1>🌡️ Temperature Monitor</h1>");
  
  html += F("<div class='card'><div class='label'>Temperature</div>"
    "<div class='value'>");
  html += String(currentTemp, 1);
  html += F("°C</div></div>");
  
  html += F("<div class='card'><div class='label'>Status</div>");
  html += F("<div class='info'>WiFi: <span class='status ");
  html += (WiFi.status() == WL_CONNECTED) ? F("ok'>Connected") : F("fail'>Offline");
  html += F("</span></div>");
  html += F("<div class='info'>RSSI: ");
  html += String(WiFi.RSSI());
  html += F(" dBm</div>");
  html += F("<div class='info'>Mode: <span class='status ");
  html += updateMode ? F("warn'>AWAKE") : F("ok'>MODEM SLEEP");
  html += F("</span></div>");
  html += F("<div class='info'>Sleep State: <span class='status ");
  html += inModemSleep ? F("ok'>SLEEPING") : F("warn'>ACTIVE");
  html += F("</span></div>");
  html += F("<div class='info'>Boot #");
  html += String(bootCount);
  html += F("</div>");
  html += F("<div class='info'>Failed sends: ");
  html += String(consecutiveFails);
  html += F("</div></div>");
  
  if (lastError.length() > 0) {
    html += F("<div class='card' style='border-color:#c62828'>"
      "<div class='label'>Last Error</div><div class='info'>");
    html += lastError;
    html += F("</div></div>");
  }
  
  html += F("<button onclick='location.href=\"/send\"'>Send Now</button>");
  html += F("<button onclick='location.href=\"/awake\"'>Force Awake</button>");
  html += F("</body></html>");
  
  server.send(200, "text/html", html);
}

void handleSend() {
  sendToServer();
  server.sendHeader("Location", "/");
  server.send(303);
}

void handleForceAwake() {
  updateMode = true;
  consecutiveFails = 0;
  server.sendHeader("Location", "/");
  server.send(303);
}

void handleTemp() {
  String json = "{\"temperature\":";
  json += String(currentTemp, 2);
  json += ",\"rssi\":";
  json += String(WiFi.RSSI());
  json += ",\"update_mode\":";
  json += updateMode ? "true" : "false";
  json += ",\"boot_count\":";
  json += String(bootCount);
  json += ",\"in_sleep\":";
  json += inModemSleep ? "true" : "false";
  json += "}";
  
  server.send(200, "application/json", json);
}

// ========================================
// OTA SETUP
// ========================================
void setupOTA() {
  ArduinoOTA.setHostname("TempMonitor");
  ArduinoOTA.setPassword("password");
  
  ArduinoOTA.onStart([]() {
    dispMsg("OTA Start");
  });
  
  ArduinoOTA.onEnd([]() {
    dispMsg("OTA Done", "Rebooting...");
  });
  
  ArduinoOTA.onProgress([](unsigned int progress, unsigned int total) {
    if (DISPLAY_ENABLED) {
      unsigned int pct = progress / (total / 100);
      display.clearDisplay();
      display.setTextSize(1);
      display.setCursor(0, 0);
      display.println(F("OTA Update"));
      display.setTextSize(2);
      display.setCursor(30, 20);
      display.print(pct);
      display.println('%');
      display.display();
    }
  });
  
  ArduinoOTA.onError([](ota_error_t error) {
    dispMsg("OTA Error");
  });
  
  ArduinoOTA.begin();
}

// ========================================
// SETUP
// ========================================
void setup() {
  Serial.begin(115200);
  Serial.println("\n\n=== BOOT ===");
  
  bootCount++;
  Serial.printf("Boot count: %u\n", bootCount);
  
  // Initialize display if enabled
  if (DISPLAY_ENABLED) {
    Wire.begin(OLED_SDA, OLED_SCL);
    if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
      // Display failed but continue anyway
    }
    display.clearDisplay();
    display.setTextColor(WHITE);
    display.display();
  }
  
  dispMsg("Temp Monitor", "v9.0 Modem", "Booting...");
  delay(1000);
  
  // Read temperature
  currentTemp = readTemperature();
  
  // Connect WiFi
  bool wifiOk = connectWiFi();
  
  if (!wifiOk) {
    // WiFi failed - stay awake to retry
    dispMsg("WiFi FAIL", "Staying awake");
    updateMode = true;
    consecutiveFails++;
    delay(5000);
    
    // Try to set up server anyway for local access
    server.on("/", handleRoot);
    server.on("/temp", handleTemp);
    server.begin();
    setupOTA();
    return; // Stay in loop()
  }
  
  // Send data and get server response
  bool sendOk = sendToServer();
  
  // Set up web server and OTA (always available now)
  MDNS.begin("tempmonitor");
  server.on("/", handleRoot);
  server.on("/send", handleSend);
  server.on("/awake", handleForceAwake);
  server.on("/temp", handleTemp);
  server.begin();
  setupOTA();
  
  // Decision point: sleep or stay awake?
  if (updateMode) {
    // Server wants us awake OR too many failures
    dispMsg("AWAKE Mode", "Full Power");
    delay(1500);
  } else {
    // Enter modem sleep to save power
    enterModemSleep();
  }
  
  lastSend = millis();
}

// ========================================
// LOOP
// ========================================
void loop() {
  unsigned long now = millis();
  
  // Check if in modem sleep
  if (inModemSleep) {
    // Check if it's time to wake up (10 minutes)
    if (now - sleepStartTime >= SEND_INTERVAL) {
      exitModemSleep();
      
      // Reconnect WiFi
      if (connectWiFi()) {
        sendToServer();
        
        // Check mode after sending
        if (!updateMode) {
          // Go back to sleep
          enterModemSleep();
        }
      } else {
        // WiFi failed - stay awake
        updateMode = true;
        consecutiveFails++;
      }
      
      lastSend = millis();
    }
    
    // Still update temperature even in modem sleep
    if (now - lastTempUpdate >= 1000) {
      lastTempUpdate = now;
      currentTemp = readTemperature();
    }
    
    delay(100); // Longer delay in sleep mode
    return;
  }
  
  // Normal awake mode operation
  ArduinoOTA.handle();
  server.handleClient();
  MDNS.update();
  
  // Update temperature every second
  if (now - lastTempUpdate >= 1000) {
    lastTempUpdate = now;
    currentTemp = readTemperature();
  }
  
  // Send to server every 10 minutes
  if (now - lastSend >= SEND_INTERVAL) {
    lastSend = now;
    
    // Reconnect WiFi if needed
    if (WiFi.status() != WL_CONNECTED) {
      connectWiFi();
    }
    
    sendToServer();
    
    // Check if server changed mode to sleep
    if (!updateMode) {
      enterModemSleep();
    }
  }
  
  delay(10);
}
