#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <ESP8266WebServer.h>
#include <DNSServer.h>
#include <ESP8266mDNS.h>
#include <ArduinoOTA.h>

// ========================================
// CONFIGURATION
// ========================================

// OLED Display settings
#define OLED_RESET -1
#define OLED_SDA 14  // SDA -> GPIO14
#define OLED_SCL 12  // SCL -> GPIO12
Adafruit_SSD1306 display(128, 64, &Wire, OLED_RESET);

// Thermistor settings
const int thermistorPin = A0;
const float SERIES_RESISTOR = 10000.0;
const float NOMINAL_RESISTANCE = 10000.0;
const float NOMINAL_TEMPERATURE = 25.0;
const float B_COEFFICIENT = 3425.0;

// WiFi credentials for internet connection (for sending data)
const char* ssid = "WIFI ssid";
const char* password = "password";

// Server endpoints
const char* tempDataURL = "http://example.com/api/receive.php";
const char* userDataURL = "http://example.com/api/receive_user.php";

// Display Settings
const bool DISPLAY_ENABLED = true;  // Set to false to save power

// Timing settings
const unsigned long SEND_INTERVAL = 10 * 60 * 1000;  // 10 minutes

// ========================================
// GLOBAL VARIABLES
// ========================================

// DNS Server for captive portal
DNSServer dnsServer;
const byte DNS_PORT = 53;

// Web server on port 80
ESP8266WebServer server(80);

// Current temperature
float currentTemp = 0.0;

// Timing
unsigned long lastSendTime = 0;
unsigned long lastTempRead = 0;

// Connection settings
const int MAX_WIFI_RETRIES = 20;
const int MAX_HTTP_RETRIES = 2;

// User tracking
struct ConnectedUser {
  String macAddress;
  String deviceName;
  String email;
  String phone;
  unsigned long connectTime;
  unsigned long disconnectTime;
  bool dataSent;
};

const int MAX_USERS = 10;
ConnectedUser users[MAX_USERS];
int userCount = 0;
bool hasNewUserData = false;

// ========================================
// SETUP
// ========================================

void setup() {
  Serial.begin(115200);
  delay(100);
  Serial.println("\n\n========================================");
  Serial.println("Temperature Monitor v4.1 - Captive Portal");
  Serial.println("========================================");
  
  // Initialize I2C with custom pins
  Wire.begin(OLED_SDA, OLED_SCL);
  
  // Initialize OLED
  if(!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
    Serial.println(F("SSD1306 allocation failed"));
    for(;;);
  }
  
  displayMessage("Temp Monitor v4.0", "Captive Portal", "Starting...", "");
  delay(2000);
  
  // Initialize user tracking
  for(int i = 0; i < MAX_USERS; i++) {
    users[i].macAddress = "";
    users[i].dataSent = false;
  }
  
  // Setup WiFi Access Point - ALWAYS ON, MAX POWER
  setupAccessPoint();
  
  // Setup DNS for captive portal
  setupCaptivePortal();
  
  // Setup web server with captive portal pages
  setupWebServer();
  
  // Setup OTA
  setupOTA();
  
  // Read initial temperature
  currentTemp = readTemperature();
  
  Serial.println("========================================");
  Serial.println("System Ready!");
  Serial.println("Features:");
  Serial.println("- AP Mode ALWAYS ON (for OTA & portal)");
  Serial.println("- Captive Portal active");
  Serial.println("- User tracking enabled");
  Serial.println("- Temperature data every 10 min");
  Serial.println("- User data sent with temp data");
  Serial.println("========================================\n");
  
  // Turn off display if disabled
  if (!DISPLAY_ENABLED) {
    display.clearDisplay();
    display.display();
    Serial.println("Display OFF (power saving mode)");
  }
}

// ========================================
// MAIN LOOP
// ========================================

void loop() {
  unsigned long currentMillis = millis();
  
  // Handle OTA updates
  ArduinoOTA.handle();
  
  // Handle DNS requests (captive portal)
  dnsServer.processNextRequest();
  
  // Handle web server requests
  server.handleClient();
  
  // Track connected clients
  trackConnectedUsers();
  
  // Read temperature every second
  if (currentMillis - lastTempRead >= 1000) {
    lastTempRead = currentMillis;
    currentTemp = readTemperature();
    
    // Update display if enabled
    if (DISPLAY_ENABLED) {
      updateDisplay();
    }
  }
  
  // Send data every 10 minutes
  if (currentMillis - lastSendTime >= SEND_INTERVAL || lastSendTime == 0) {
    lastSendTime = currentMillis;
    sendAllDataToServer();
  }
  
  // Small delay to prevent watchdog reset
  delay(10);
}

// ========================================
// WIFI SETUP - ALWAYS ON, MAX POWER
// ========================================

void setupAccessPoint() {
  Serial.println("Setting up Access Point (ALWAYS ON)...");
  
  // Configure AP with custom settings
  WiFi.mode(WIFI_AP);
  
  // Use default ESP SSID, open network
  WiFi.softAP("");  // Default ESP_XXXXXX, open network
  
  // SET MAXIMUM POWER - CRITICAL FOR OTA
  WiFi.setOutputPower(20.5);  // Maximum power (20.5 dBm)
  
  IPAddress IP = WiFi.softAPIP();
  String apSSID = WiFi.softAPSSID();
  
  Serial.println("========================================");
  Serial.println("Access Point Configuration:");
  Serial.print("  SSID: ");
  Serial.println(apSSID);
  Serial.print("  IP: ");
  Serial.println(IP);
  Serial.println("  Security: Open Network");
  Serial.println("  Power: MAX (20.5 dBm)");
  Serial.println("  Status: ALWAYS ON");
  Serial.println("========================================");
  
  displayMessage("AP ALWAYS ON", apSSID.c_str(), "IP: " + IP.toString(), "MAX POWER");
  delay(3000);
}

// ========================================
// CAPTIVE PORTAL SETUP
// ========================================

void setupCaptivePortal() {
  // Start DNS server for captive portal
  // Redirect all domains to AP IP
  dnsServer.start(DNS_PORT, "*", WiFi.softAPIP());
  
  Serial.println("Captive Portal DNS started");
  Serial.println("All domains redirect to AP IP");
}

// ========================================
// WEB SERVER SETUP WITH CAPTIVE PORTAL
// ========================================

void setupWebServer() {
  // Captive portal detection endpoints
  server.on("/generate_204", handleCaptivePortal);  // Android
  server.on("/fwlink", handleCaptivePortal);        // Microsoft
  server.on("/hotspot-detect.html", handleCaptivePortal); // Apple
  
  // Main landing page
  server.on("/", HTTP_GET, handleRoot);
  
  // Temperature endpoint
  server.on("/temp", HTTP_GET, handleTempEndpoint);
  
  // Submit form endpoint
  server.on("/submit", HTTP_POST, handleFormSubmit);
  
  // Catch all for captive portal
  server.onNotFound(handleCaptivePortal);
  
  server.begin();
  Serial.println("Web server started with captive portal");
}

// ========================================
// WEB PAGE HANDLERS
// ========================================

void handleCaptivePortal() {
  // Redirect to home page
  server.sendHeader("Location", "http://" + WiFi.softAPIP().toString(), true);
  server.send(302, "text/plain", "");
}

void handleRoot() {
  String html = "<!DOCTYPE html><html><head>";
  html += "<meta charset='UTF-8'>";
  html += "<meta name='viewport' content='width=device-width, initial-scale=1'>";
  html += "<title>Temperature Monitor</title>";
  html += "<style>";
  html += "* { margin: 0; padding: 0; box-sizing: border-box; }";
  html += "body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;";
  html += "  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);";
  html += "  min-height: 100vh; padding: 20px; }";
  html += ".container { max-width: 500px; margin: 0 auto; }";
  html += ".card { background: white; border-radius: 20px; padding: 30px;";
  html += "  box-shadow: 0 20px 60px rgba(0,0,0,0.3); margin-bottom: 20px; }";
  html += "h1 { color: #333; font-size: 28px; margin-bottom: 10px; text-align: center; }";
  html += ".temp-display { text-align: center; padding: 30px 0; }";
  html += ".temp-value { font-size: 64px; font-weight: bold; color: #667eea; }";
  html += ".temp-unit { font-size: 24px; color: #999; }";
  html += ".description { color: #666; line-height: 1.6; margin: 20px 0; text-align: center; }";
  html += ".link-button { display: block; background: #667eea; color: white;";
  html += "  text-decoration: none; padding: 15px; border-radius: 10px;";
  html += "  text-align: center; margin: 15px 0; font-weight: 600;";
  html += "  transition: background 0.3s; }";
  html += ".link-button:hover { background: #5568d3; }";
  html += ".form-group { margin: 15px 0; }";
  html += "label { display: block; color: #333; margin-bottom: 5px; font-weight: 500; }";
  html += "input { width: 100%; padding: 12px; border: 2px solid #e0e0e0;";
  html += "  border-radius: 8px; font-size: 16px; transition: border 0.3s; }";
  html += "input:focus { outline: none; border-color: #667eea; }";
  html += ".submit-btn { width: 100%; padding: 15px; background: #667eea;";
  html += "  color: white; border: none; border-radius: 10px; font-size: 16px;";
  html += "  font-weight: 600; cursor: pointer; transition: background 0.3s; }";
  html += ".submit-btn:hover { background: #5568d3; }";
  html += ".optional { color: #999; font-size: 12px; }";
  html += ".footer { text-align: center; color: white; margin-top: 20px; font-size: 14px; }";
  html += "</style></head><body>";
  
  html += "<div class='container'>";
  
  // Temperature Card
  html += "<div class='card'>";
  html += "<h1>🌡️ Temperature Monitor</h1>";
  html += "<div class='temp-display'>";
  html += "<div class='temp-value'>" + String(currentTemp, 1) + "<span class='temp-unit'>°C</span></div>";
  html += "</div>";
  html += "<p class='description'>";
  html += "Welcome! This device monitors temperature in real-time. ";
  html += "The sensor reads environmental temperature every second and ";
  html += "uploads data to the cloud every 10 minutes for analysis and tracking.";
  html += "</p>";
  html += "<a href='/temp' class='link-button'>📊 Get JSON Data</a>";
  html += "</div>";
  
  // Contact Form Card
  html += "<div class='card'>";
  html += "<h1>📬 Stay Connected</h1>";
  html += "<p class='description' style='margin-bottom:20px;'>";
  html += "Want to receive temperature alerts or updates? Leave your contact info!";
  html += "</p>";
  html += "<form action='/submit' method='POST'>";
  html += "<div class='form-group'>";
  html += "<label>Email <span class='optional'>(optional)</span></label>";
  html += "<input type='email' name='email' placeholder='your@email.com'>";
  html += "</div>";
  html += "<div class='form-group'>";
  html += "<label>Phone <span class='optional'>(optional)</span></label>";
  html += "<input type='tel' name='phone' placeholder='+92 300 1234567'>";
  html += "</div>";
  html += "<button type='submit' class='submit-btn'>Submit</button>";
  html += "</form>";
  html += "</div>";
  
  html += "<div class='footer'>Temperature Monitor v4.0<br>Captive Portal Active</div>";
  html += "</div>";
  
  html += "</body></html>";
  
  server.send(200, "text/html", html);
  
  // Track this page view
  String clientMAC = getClientMAC();
  Serial.println("Root page served to: " + clientMAC);
}

void handleTempEndpoint() {
  String json = "{\"temperature\":" + String(currentTemp, 2) + "}";
  server.send(200, "application/json", json);
  
  Serial.println("GET /temp served: " + json);
}

void handleFormSubmit() {
  String email = server.arg("email");
  String phone = server.arg("phone");
  String clientMAC = getClientMAC();
  
  // Store user info
  updateUserInfo(clientMAC, email, phone);
  
  Serial.println("Form submitted:");
  Serial.println("  MAC: " + clientMAC);
  Serial.println("  Email: " + (email.length() > 0 ? email : "Not provided"));
  Serial.println("  Phone: " + (phone.length() > 0 ? phone : "Not provided"));
  
  // Thank you page
  String html = "<!DOCTYPE html><html><head>";
  html += "<meta charset='UTF-8'>";
  html += "<meta name='viewport' content='width=device-width, initial-scale=1'>";
  html += "<title>Thank You</title>";
  html += "<style>";
  html += "body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);";
  html += "  display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }";
  html += ".card { background: white; border-radius: 20px; padding: 40px; text-align: center;";
  html += "  box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 400px; }";
  html += "h1 { color: #667eea; font-size: 32px; margin-bottom: 20px; }";
  html += "p { color: #666; line-height: 1.6; margin-bottom: 30px; }";
  html += ".btn { display: inline-block; background: #667eea; color: white; text-decoration: none;";
  html += "  padding: 15px 30px; border-radius: 10px; font-weight: 600; }";
  html += "</style></head><body>";
  html += "<div class='card'>";
  html += "<h1>✓ Thank You!</h1>";
  html += "<p>Your information has been saved. You'll be notified about temperature updates.</p>";
  html += "<a href='/' class='btn'>Back to Home</a>";
  html += "</div>";
  html += "</body></html>";
  
  server.send(200, "text/html", html);
}

// ========================================
// USER TRACKING
// ========================================

String getClientMAC() {
  // Get the MAC address of the connected client
  // This is approximate as ESP8266 AP mode doesn't easily expose client MACs
  // We use IP as a proxy
  return server.client().remoteIP().toString();
}

void trackConnectedUsers() {
  // Check for new connections
  int stationCount = WiFi.softAPgetStationNum();
  
  static int lastStationCount = 0;
  if (stationCount != lastStationCount) {
    Serial.print("Connected clients: ");
    Serial.println(stationCount);
    
    if (stationCount > lastStationCount) {
      Serial.println("New client connected!");
      hasNewUserData = true;
    }
    
    lastStationCount = stationCount;
    
    if (DISPLAY_ENABLED) {
      updateDisplay();
    }
  }
}

void updateUserInfo(String identifier, String email, String phone) {
  // Find existing user or add new one
  int userIndex = -1;
  
  // Look for existing user
  for (int i = 0; i < userCount; i++) {
    if (users[i].macAddress == identifier) {
      userIndex = i;
      break;
    }
  }
  
  // Add new user if not found
  if (userIndex == -1 && userCount < MAX_USERS) {
    userIndex = userCount;
    users[userIndex].macAddress = identifier;
    users[userIndex].connectTime = millis() / 1000;
    users[userIndex].deviceName = "Unknown";
    users[userIndex].dataSent = false;
    userCount++;
  }
  
  // Update user info
  if (userIndex != -1) {
    if (email.length() > 0) {
      users[userIndex].email = email;
    }
    if (phone.length() > 0) {
      users[userIndex].phone = phone;
    }
    hasNewUserData = true;
  }
}

// ========================================
// OTA SETUP
// ========================================

void setupOTA() {
  ArduinoOTA.setHostname("TempMonitor");
  ArduinoOTA.setPassword("admin123");
  
  ArduinoOTA.onStart([]() {
    Serial.println("OTA Update Starting...");
    displayMessage("OTA Update", "Starting...", "", "");
  });
  
  ArduinoOTA.onEnd([]() {
    Serial.println("\nOTA Update Complete!");
    displayMessage("OTA Update", "Complete!", "Rebooting...", "");
  });
  
  ArduinoOTA.onProgress([](unsigned int progress, unsigned int total) {
    unsigned int percent = (progress / (total / 100));
    Serial.printf("Progress: %u%%\r", percent);
    
    display.clearDisplay();
    display.setTextSize(1);
    display.setCursor(0, 0);
    display.println("OTA Update");
    display.setTextSize(2);
    display.setCursor(30, 25);
    display.print(percent);
    display.println("%");
    display.setTextSize(1);
    display.drawRect(10, 45, 108, 10, WHITE);
    display.fillRect(12, 47, (percent * 104) / 100, 6, WHITE);
    display.display();
  });
  
  ArduinoOTA.onError([](ota_error_t error) {
    Serial.printf("OTA Error[%u]: ", error);
    const char* errorMsg = "";
    switch(error) {
      case OTA_AUTH_ERROR: errorMsg = "Auth Failed"; break;
      case OTA_BEGIN_ERROR: errorMsg = "Begin Failed"; break;
      case OTA_CONNECT_ERROR: errorMsg = "Connect Failed"; break;
      case OTA_RECEIVE_ERROR: errorMsg = "Receive Failed"; break;
      case OTA_END_ERROR: errorMsg = "End Failed"; break;
    }
    Serial.println(errorMsg);
    displayMessage("OTA Error!", errorMsg, "", "");
  });
  
  ArduinoOTA.begin();
  Serial.println("OTA Ready (Password: admin123)");
  Serial.println("AP Mode ALWAYS ON for OTA access");
}

// ========================================
// TEMPERATURE READING
// ========================================

float readTemperature() {
  int rawValue = analogRead(thermistorPin);
  float voltage = rawValue * (3.3 / 1024.0);
  
  if (voltage >= 3.3) {
    voltage = 3.29;
  }
  
  float resistance = SERIES_RESISTOR * (3.3 / voltage - 1.0);
  
  float steinhart = resistance / NOMINAL_RESISTANCE;
  steinhart = log(steinhart);
  steinhart /= B_COEFFICIENT;
  steinhart += 1.0 / (NOMINAL_TEMPERATURE + 273.15);
  steinhart = 1.0 / steinhart;
  steinhart -= 273.15;
  
  if (steinhart < -40 || steinhart > 85) {
    Serial.println("Warning: Temperature out of range!");
    return currentTemp;
  }
  
  return steinhart;
}

// ========================================
// SEND DATA TO SERVER
// ========================================

void sendAllDataToServer() {
  Serial.println("\n========================================");
  Serial.println("Sending data to server...");
  Serial.println("========================================");
  
  displayMessage("Sending Data", "Connecting WiFi...", "", "");
  
  // Switch to AP+STA mode (keep AP always on)
  WiFi.mode(WIFI_AP_STA);
  WiFi.setOutputPower(20.5);  // Maintain max power
  WiFi.begin(ssid, password);
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < MAX_WIFI_RETRIES) {
    delay(500);
    Serial.print(".");
    attempts++;
  }
  Serial.println();
  
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi connection failed!");
    displayMessage("Send Failed", "No WiFi", "Retry in 10min", "");
    delay(3000);
    
    WiFi.disconnect();
    WiFi.mode(WIFI_AP);
    WiFi.setOutputPower(20.5);
    
    if (!DISPLAY_ENABLED) {
      display.clearDisplay();
      display.display();
    }
    return;
  }
  
  Serial.println("WiFi connected!");
  Serial.print("Signal: ");
  Serial.print(WiFi.RSSI());
  Serial.println(" dBm");
  
  // STEP 1: Send temperature data
  bool tempSent = sendTemperatureData();
  
  // STEP 2: Send user data (only if there's new data and temp was sent successfully)
  if (tempSent && hasNewUserData) {
    sendUserData();
  }
  
  // Disconnect and return to AP-only mode
  WiFi.disconnect();
  WiFi.mode(WIFI_AP);
  WiFi.setOutputPower(20.5);  // Restore max power
  
  Serial.println("Back to AP mode (ALWAYS ON)");
  Serial.println("========================================\n");
  
  if (!DISPLAY_ENABLED) {
    display.clearDisplay();
    display.display();
  }
}

bool sendTemperatureData() {
  Serial.println("\n--- Sending Temperature Data ---");
  
  String jsonData = "{\"temperature\":" + String(currentTemp, 2) + "}";
  Serial.println("Payload: " + jsonData);
  
  bool success = false;
  int httpCode = 0;
  
  for (int retry = 0; retry < MAX_HTTP_RETRIES && !success; retry++) {
    WiFiClient client;
    HTTPClient http;
    
    http.setTimeout(10000);
    http.begin(client, tempDataURL);
    http.addHeader("Content-Type", "application/json");
    
    httpCode = http.POST(jsonData);
    
    if (httpCode > 0) {
      Serial.print("Response code: ");
      Serial.println(httpCode);
      Serial.print("Response: ");
      Serial.println(http.getString());
      
      if (httpCode == 200) {
        success = true;
      }
    } else {
      Serial.print("Error: ");
      Serial.println(httpCode);
    }
    
    http.end();
    
    if (!success && retry < MAX_HTTP_RETRIES - 1) {
      delay(2000);
    }
  }
  
  if (success) {
    displayMessage("Temp Data Sent!", "Code: " + String(httpCode), String(currentTemp, 2) + " C", "");
    Serial.println("✓ Temperature data sent successfully");
  } else {
    displayMessage("Temp Send Failed!", "Error: " + String(httpCode), "", "");
    Serial.println("✗ Temperature data send failed");
  }
  
  delay(2000);
  return success;
}

void sendUserData() {
  Serial.println("\n--- Sending User Data ---");
  
  // Build JSON array of users
  String jsonData = "{\"users\":[";
  
  int sentCount = 0;
  for (int i = 0; i < userCount; i++) {
    if (!users[i].dataSent) {
      if (sentCount > 0) jsonData += ",";
      
      jsonData += "{";
      jsonData += "\"mac\":\"" + users[i].macAddress + "\",";
      jsonData += "\"device\":\"" + users[i].deviceName + "\",";
      jsonData += "\"email\":\"" + users[i].email + "\",";
      jsonData += "\"phone\":\"" + users[i].phone + "\",";
      jsonData += "\"connect_time\":" + String(users[i].connectTime) + ",";
      
      unsigned long duration = (millis() / 1000) - users[i].connectTime;
      jsonData += "\"duration\":" + String(duration);
      jsonData += "}";
      
      sentCount++;
    }
  }
  
  jsonData += "]}";
  
  if (sentCount == 0) {
    Serial.println("No new user data to send");
    return;
  }
  
  Serial.println("Payload: " + jsonData);
  Serial.print("Sending data for ");
  Serial.print(sentCount);
  Serial.println(" user(s)");
  
  bool success = false;
  
  for (int retry = 0; retry < MAX_HTTP_RETRIES && !success; retry++) {
    WiFiClient client;
    HTTPClient http;
    
    http.setTimeout(10000);
    http.begin(client, userDataURL);
    http.addHeader("Content-Type", "application/json");
    
    int httpCode = http.POST(jsonData);
    
    if (httpCode > 0) {
      Serial.print("Response code: ");
      Serial.println(httpCode);
      Serial.print("Response: ");
      Serial.println(http.getString());
      
      if (httpCode == 200) {
        success = true;
      }
    } else {
      Serial.print("Error: ");
      Serial.println(httpCode);
    }
    
    http.end();
    
    if (!success && retry < MAX_HTTP_RETRIES - 1) {
      delay(2000);
    }
  }
  
  if (success) {
    // Mark users as sent
    for (int i = 0; i < userCount; i++) {
      users[i].dataSent = true;
    }
    hasNewUserData = false;
    
    displayMessage("User Data Sent!", String(sentCount) + " users", "Success", "");
    Serial.println("✓ User data sent successfully");
  } else {
    displayMessage("User Send Failed!", "Will retry", "", "");
    Serial.println("✗ User data send failed");
  }
  
  delay(2000);
}

// ========================================
// DISPLAY FUNCTIONS
// ========================================

void updateDisplay() {
  display.clearDisplay();
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.print("AP: ");
  display.println(WiFi.softAPSSID());
  
  display.setTextSize(3);
  display.setCursor(10, 20);
  display.print(currentTemp, 1);
  
  display.setTextSize(1);
  display.setCursor(100, 22);
  display.print("o");
  display.setTextSize(2);
  display.setCursor(105, 27);
  display.print("C");
  
  display.setTextSize(1);
  display.setCursor(0, 48);
  display.print("Users: ");
  display.println(WiFi.softAPgetStationNum());
  
  display.setCursor(0, 56);
  int minutesLeft = (SEND_INTERVAL - (millis() - lastSendTime)) / 60000;
  display.print("Send: ");
  display.print(minutesLeft);
  display.print("min");
  
  display.display();
}

void displayMessage(const char* line1, String line2, String line3, const char* line4) {
  display.clearDisplay();
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println(line1);
  display.setCursor(0, 12);
  display.println(line2);
  display.setCursor(0, 24);
  display.println(line3);
  display.setCursor(0, 36);
  display.println(line4);
  display.display();
}
