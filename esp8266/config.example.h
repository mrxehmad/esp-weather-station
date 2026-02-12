// ESP8266 Configuration Template
// Copy this to your main .ino file and fill in your details

// ========================================
// WiFi Configuration
// ========================================
const char* ssid = "YOUR_WIFI_SSID";          // Your WiFi network name
const char* password = "YOUR_WIFI_PASSWORD";   // Your WiFi password

// ========================================
// Server Configuration
// ========================================
const char* tempDataURL = "http://YOUR_SERVER_IP/temp-station/api/receive.php";
const char* userDataURL = "http://YOUR_SERVER_IP/temp-station/api/receive_user.php";

// Examples:
// const char* tempDataURL = "http://192.168.1.100/temp-station/api/receive.php";
// const char* tempDataURL = "http://example.com/api/receive.php";

// ========================================
// Thermistor Configuration
// ========================================
const float SERIES_RESISTOR = 10000.0;         // 10kΩ resistor value
const float NOMINAL_RESISTANCE = 10000.0;      // Thermistor resistance at 25°C
const float NOMINAL_TEMPERATURE = 25.0;        // Reference temperature (°C)
const float B_COEFFICIENT = 3425.0;            // Beta value (check thermistor datasheet)

// Common Beta values:
// - 3435 (most common)
// - 3425
// - 3950
// - 4050

// ========================================
// Display Configuration
// ========================================
const bool DISPLAY_ENABLED = false;  // true = display ON, false = display OFF (saves power)

// ========================================
// Timing Configuration
// ========================================
const unsigned long SEND_INTERVAL = 10 * 60 * 1000;  // Data upload interval (milliseconds)
// Examples:
// 5 minutes:  5 * 60 * 1000
// 10 minutes: 10 * 60 * 1000
// 30 minutes: 30 * 60 * 1000
// 1 hour:     60 * 60 * 1000

// ========================================
// OTA Configuration
// ========================================
// Hostname: TempMonitor
// Password: admin123 (CHANGE THIS FOR PRODUCTION!)

// To change OTA password, modify this line in setupOTA():
// ArduinoOTA.setPassword("YOUR_SECURE_PASSWORD");

// ========================================
// Pin Configuration (if different from defaults)
// ========================================
#define OLED_SDA 14  // GPIO14 (D5 on NodeMCU)
#define OLED_SCL 12  // GPIO12 (D6 on NodeMCU)

// Thermistor pin (cannot be changed on ESP8266)
const int thermistorPin = A0;  // Analog pin

// ========================================
// Advanced Configuration
// ========================================
const int MAX_WIFI_RETRIES = 20;   // WiFi connection attempts before giving up
const int MAX_HTTP_RETRIES = 2;    // HTTP request retries on failure
