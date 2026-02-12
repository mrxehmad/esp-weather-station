# Temperature Monitor System

A complete IoT temperature monitoring system with ESP8266, captive portal, and real-time web dashboard.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![Platform](https://img.shields.io/badge/platform-ESP8266-orange.svg)
![Database](https://img.shields.io/badge/database-SQLite-green.svg)

## Features

- **Real-time Temperature Monitoring** - Continuous environmental temperature tracking
- **Captive Portal** - Automatic user engagement and data collection
- **Web Dashboard** - Professional iOS-styled interface with analytics
- **SQLite Database** - Reliable, corruption-free data storage
- **OTA Updates** - Over-the-air firmware updates
- **User Tracking** - Analytics on connected devices and user engagement
- **RESTful API** - Easy integration with other services

## System Architecture

```
┌─────────────┐         ┌──────────────┐         ┌─────────────┐
│  ESP8266    │────────▶│  Web Server  │────────▶│  Dashboard  │
│  Sensor     │  HTTP   │  PHP/SQLite  │  AJAX   │  HTML/CSS/JS│
└─────────────┘         └──────────────┘         └─────────────┘
      │                        │
      │                        │
      ▼                        ▼
┌─────────────┐         ┌──────────────┐
│  Captive    │         │   SQLite DB  │
│  Portal     │         │   - Temps    │
│  (User Data)│         │   - Users    │
└─────────────┘         └──────────────┘
```

## Hardware Requirements

- **ESP8266** (NodeMCU, Wemos D1 Mini, or similar)
- **NTC Thermistor** (10kΩ @ 25°C, Beta 3425)
- **10kΩ Resistor** (for voltage divider)
- **OLED Display** (128x64, SSD1306, optional)
- **Power Supply** (5V USB or battery)

### Wiring Diagram

```
ESP8266          Thermistor & Resistor
--------         ---------------------
A0    ──────────┬──── Thermistor ──── 3.3V
                │
                └──── 10kΩ Resistor ── GND

GPIO14 (D5) ──── SDA (OLED)
GPIO12 (D6) ──── SCL (OLED)
```

## Software Requirements

### Server
- PHP 8.0+ with PDO SQLite extension
- Apache/Nginx web server
- SQLite3

### ESP8266
- Arduino IDE 1.8.19+
- ESP8266 Board Package 3.0.0+
- Required Libraries:
  - Adafruit GFX
  - Adafruit SSD1306
  - ESP8266WiFi
  - ESP8266HTTPClient
  - ESP8266WebServer
  - DNSServer
  - ArduinoOTA
  - NTPClient

## Installation

### 1. Server Setup

```bash
# Clone repository
git clone https://github.com/yourusername/temperature-monitor.git
cd temperature-monitor

# Copy to web server directory
sudo cp -r server/* /var/www/html/temp-station/

# Install PHP SQLite extension (if needed)
sudo apt-get install php-sqlite3

# Set permissions
cd /var/www/html/temp-station
sudo chown -R www-data:www-data .
sudo chmod 755 data api
sudo chmod 644 api/*.php index.html

# Initialize database
sudo php api/init_database.php

# Restart web server
sudo systemctl restart apache2
```

### 2. ESP8266 Setup

```cpp
// 1. Open esp8266/temperature_monitor.ino in Arduino IDE

// 2. Configure your WiFi credentials
const char* ssid = "YOUR_WIFI_SSID";
const char* password = "YOUR_WIFI_PASSWORD";

// 3. Set your server URL
const char* tempDataURL = "http://YOUR_SERVER_IP/temp-station/api/receive.php";
const char* userDataURL = "http://YOUR_SERVER_IP/temp-station/api/receive_user.php";

// 4. Upload to ESP8266
```

## Configuration

### Temperature Sensor Calibration

Adjust these constants in the ESP8266 code:

```cpp
const float B_COEFFICIENT = 3425.0;  // Beta value for your thermistor
const float NOMINAL_RESISTANCE = 10000.0;  // Resistance at 25°C
const float NOMINAL_TEMPERATURE = 25.0;  // Reference temperature
```

### Display Settings

```cpp
const bool DISPLAY_ENABLED = false;  // Set to true to enable OLED
```

### Data Upload Interval

```cpp
const unsigned long SEND_INTERVAL = 10 * 60 * 1000;  // 10 minutes (in milliseconds)
```

## API Endpoints

### Temperature Data

**POST** `/api/receive.php`
```json
{
  "temperature": 25.5
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Temperature stored",
  "temperature": 25.5,
  "total_records": 245
}
```

### User Data

**POST** `/api/receive_user.php`
```json
{
  "users": [
    {
      "mac": "192.168.4.2",
      "email": "user@example.com",
      "phone": "+1234567890",
      "connect_time": 1738368000,
      "duration": 300
    }
  ]
}
```

### Get Temperature Data

**GET** `/api/getData.php?hours=24`

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "timestamp": 1738368000,
      "temperature": 25.5,
      "received_at": 1738368010
    }
  ],
  "stats": {
    "current": 25.5,
    "min": 18.2,
    "max": 28.4,
    "avg": 23.1,
    "total_readings": 1440
  }
}
```

### Get User Data

**GET** `/api/getUserData.php?hours=168`

**Response:**
```json
{
  "status": "success",
  "data": [...],
  "stats": {
    "total_users": 45,
    "unique_devices": 32,
    "users_with_email": 18,
    "users_with_phone": 12,
    "connections_24h": 8
  }
}
```

## Database Schema

### `temperature_data`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER | Primary key |
| received_at | INTEGER | Unix timestamp (server time) |
| device_timestamp | INTEGER | Unix timestamp (device time) |
| created_at | DATETIME | Auto-generated timestamp |

### `temperature_samples`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER | Primary key |
| data_id | INTEGER | Foreign key to temperature_data |
| temperature | REAL | Temperature in Celsius |
| offset | INTEGER | Seconds offset from batch start |

### `connected_users`
| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER | Primary key |
| mac_address | TEXT | Device identifier |
| device_name | TEXT | Device name |
| email | TEXT | User email (optional) |
| phone | TEXT | User phone (optional) |
| connect_time | INTEGER | Connection timestamp |
| duration | INTEGER | Connection duration (seconds) |
| received_at | INTEGER | Server received timestamp |

## OTA Updates

The ESP8266 supports Over-The-Air updates for easy firmware upgrades without physical access.

### Using Arduino IDE

1. Connect to the ESP8266's WiFi network (ESP_XXXXXX)
2. Tools → Port → Network Ports → TempMonitor
3. Upload sketch normally
4. Enter password: `admin123`

### Security Note

Change the default OTA password in production:
```cpp
ArduinoOTA.setPassword("YOUR_SECURE_PASSWORD");
```

## Captive Portal

When users connect to the ESP8266's WiFi network, they are automatically redirected to a web portal where they can:
- View current temperature
- Submit contact information (email/phone)
- Access temperature data via JSON endpoint

The captive portal works on iOS, Android, and desktop devices.

## Dashboard Features

- **Temperature Tab**
  - Real-time temperature display
  - Historical trends chart
  - Temperature distribution histogram
  - Time range filters (1H, 6H, 24H, 3D, 7D)

- **Users Tab**
  - Total connections and unique devices
  - User contact information (emails/phones collected)
  - Connection duration statistics
  - Recent connections table

## Database Management

### Show Database Info
```bash
sudo php api/manage_database.php info
```

### View Statistics
```bash
sudo php api/manage_database.php stats
```

### Clean Old Data
```bash
sudo php api/manage_database.php cleanup 30  # Keep last 30 days
```

### Optimize Database
```bash
sudo php api/manage_database.php vacuum
```

### Export to JSON
```bash
sudo php api/manage_database.php export backup.json
```

### Import from JSON
```bash
sudo php api/manage_database.php import backup.json
```

## Troubleshooting

### ESP8266 Won't Connect to WiFi
- Check SSID and password in code
- Verify WiFi network is 2.4GHz (ESP8266 doesn't support 5GHz)
- Check signal strength (move closer to router)

### Server Returns 404
- Verify file paths in ESP8266 code
- Check Apache virtual host configuration
- Ensure API files are in correct directory

### Database Locked Error
- Check file permissions on database file
- Ensure www-data user owns database
- WAL mode should prevent most locking issues

### Temperature Readings Incorrect
- Verify thermistor specifications (Beta value)
- Check wiring and connections
- Calibrate B_COEFFICIENT constant

## Performance

- **Database**: Handles 100,000+ temperature readings efficiently
- **Response Time**: API responds in <50ms for most queries
- **Storage**: ~1KB per temperature reading (with SQLite overhead)
- **Concurrent Users**: Supports 50+ simultaneous captive portal connections

## Security Recommendations

For production deployment:

1. **Use HTTPS** - Encrypt all communication
2. **Change OTA Password** - Use strong password
3. **Add API Authentication** - Implement token-based auth
4. **Rate Limiting** - Prevent abuse
5. **Input Validation** - Already implemented, review regularly
6. **Database Backups** - Regular automated backups
7. **Restrict API Access** - Use firewall rules if needed

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- ESP8266 Community for excellent documentation
- Adafruit for display libraries
- Chart.js for beautiful graphs
- SQLite for reliable database engine

## Changelog

### v4.0 (Latest)
- Added captive portal functionality
- User tracking and analytics
- Migrated to SQLite database
- Professional iOS-styled dashboard
- OTA update support

### v3.0
- Added OLED display support
- Improved error handling
- Better WiFi management

### v2.0
- Fixed offset calculation
- HTTP retry logic
- NTP time synchronization

### v1.0
- Initial release
- Basic temperature monitoring
- JSON file storage

## Support

For issues and questions:
- Open an issue on GitHub
- Check existing issues for solutions
- Review troubleshooting section

## Roadmap

- [ ] Mobile app (iOS/Android)
- [ ] Email/SMS alerts
- [ ] Multi-sensor support
- [ ] Historical data export (CSV)
- [ ] Grafana integration
- [ ] MQTT support
- [ ] Temperature predictions using ML
