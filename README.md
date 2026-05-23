# Temperature Monitor System

A complete temperature monitoring system using ESP8266, thermistor sensor, and web-based dashboard.

## 📁 File Structure

```
temperature/
├── index.html              # Main dashboard
├── .htaccess              # Apache configuration
├── api/
│   ├── receive.php        # Endpoint to receive data from ESP8266 (+ Sinric Pro forward)
│   ├── lib/
│   │   └── SinricClient.php  # Sinric Pro REST integration
│   └── getData.php        # Endpoint to retrieve data for dashboard
└── data/
    ├── sinric_config.json      # Sinric API credentials (see example)
    ├── sinric_config.example.json
    └── temperature_data.json  # JSON data storage (auto-created)
```

## 🚀 Installation

### 1. Server Setup

#### Option A: Using Apache/PHP Server

1. Copy the entire `temperature` folder to your web server root:
   ```
   /var/www/html/temperature/
   ```

2. Make sure the data directory is writable:
   ```bash
   chmod 755 data/
   ```

3. Ensure PHP and Apache mod_rewrite are enabled:
   ```bash
   sudo a2enmod rewrite
   sudo systemctl restart apache2
   ```

#### Option B: Using PHP Built-in Server (Testing Only)

```bash
cd temperature
php -S 0.0.0.0:8080
```

### 2. ESP8266 Configuration

Firmware POST format (matches `receive.php`):

```json
{"temperature":21.70,"rssi":-62,"boot_count":12,"fails":0}
```

Required server response field: **`update_mode`** (boolean).  
`false` = modem sleep (10 min interval). `true` = stay awake for OTA / recovery.

**Important:** use **HTTPS** if your host redirects HTTP → HTTPS (otherwise ESP may get HTTP 301 and treat send as failed):

```cpp
static const char SERVER_URL[] PROGMEM = "https://temp.ehmi.se/api/receive.php";
```

Check last ESP contact:

```
GET https://temp.ehmi.se/api/device_status.php
```

Test with curl (full firmware payload):

```bash
curl -sS -X POST "https://temp.ehmi.se/api/receive.php" \
  -H "Content-Type: application/json" \
  -d '{"temperature":21.7,"rssi":-58,"boot_count":42,"fails":0}'
```

### 3. Access Dashboard

Open your browser and navigate to:
```
http://YOUR_SERVER_IP/temperature/
```

## 📊 Dashboard Features

- **Real-time Statistics**
  - Current temperature
  - Average temperature
  - Min/Max values
  - Total readings

- **Interactive Charts**
  - Temperature over time (line chart)
  - Temperature distribution (histogram)
  - Time filters: 1H, 6H, 24H, 3D, 7D

- **Auto-refresh**
  - Dashboard refreshes every 5 minutes
  - Manual refresh button available

## 🏠 Sinric Pro (Alexa / Google Home)

When the ESP posts temperature to `receive.php`, the server can forward the reading to [Sinric Pro](https://sinric.pro) so voice assistants see live temperature.

### Setup

1. In [Sinric Pro Portal](https://portal.sinric.pro), create a **Temperature Sensor** device and note its **Device ID**.
2. Create an **API Key**: [Credentials → New API Key](https://portal.sinric.pro/credential/new/apikey).
3. Copy `data/sinric_config.example.json` to `data/sinric_config.json` (or edit the existing file).
4. Set your values and enable the integration:

```json
{
    "enabled": true,
    "api_key": "your-api-key-here",
    "device_id": "your-temperature-sensor-device-id",
    "min_interval_seconds": 60
}
```

Sinric limits sensor events (about once per 60 seconds). `min_interval_seconds` matches that so extra ESP posts are still stored locally but only forwarded when the interval allows.

### Flow

```
ESP8266  --POST-->  receive.php  --stores-->  SQLite
                         |
                         +--event-->  api.sinric.pro  (currentTemperature)
```

The ESP response may include a `sinric` object when forwarding is enabled (success, skipped due to rate limit, or error).

## 🔌 API Endpoints

### POST /api/receive.php
Receives temperature data from ESP8266

**Request:**
```json
{
  "timestamp": 1738368000,
  "samples": [
    {"temp": 18.50, "offset": 0},
    {"temp": 18.60, "offset": 300},
    {"temp": 18.55, "offset": 600},
    {"temp": 18.70, "offset": 900},
    {"temp": 18.65, "offset": 1200},
    {"temp": 18.80, "offset": 1500}
  ]
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Data stored successfully",
  "samples_received": 6
}
```

### GET /api/getData.php
Retrieves temperature data for dashboard

**Parameters:**
- `hours` (optional): Number of hours to retrieve (default: 24)
- `limit` (optional): Maximum number of readings (default: 1000)

**Example:**
```
GET /api/getData.php?hours=24
```

**Response:**
```json
{
  "status": "success",
  "data": [
    {
      "timestamp": 1738368000,
      "temperature": 18.50,
      "received_at": 1738368010
    }
  ],
  "stats": {
    "current": 18.80,
    "min": 18.50,
    "max": 18.80,
    "avg": 18.65,
    "total_readings": 6
  },
  "filter": {
    "hours": 24,
    "from": "2026-01-30 12:00:00",
    "to": "2026-01-31 12:00:00"
  }
}
```

## 🔧 Troubleshooting

### ESP8266 Can't Connect to Server

1. Check your server IP address
2. Make sure the server is accessible from ESP8266's network
3. Check firewall settings
4. Verify the URL is correct (include `/api/receive.php`)

### Dashboard Shows "No Data Available"

1. Wait for first hourly data transmission from ESP8266
2. Check if `data/temperature_data.json` file exists
3. Verify file permissions on the data directory

### Permission Errors (`readonly database`)

PHP runs as `www-data`, but files deployed as **root** cannot be written. Fix on the server:

```bash
cd /var/www/temp-station
sudo bash scripts/fix-permissions.sh
```

Or manually:

```bash
sudo chown -R www-data:www-data /var/www/temp-station/data
sudo chmod 775 /var/www/temp-station/data
sudo chmod 664 /var/www/temp-station/data/temperature.db
sudo chmod 664 /var/www/temp-station/data/settings.json
sudo chmod 664 /var/www/temp-station/data/sinric_config.json
```

Then reload: `https://your-host/api/getData.php?hours=24`

## 📈 Data Storage

- Data is stored in JSON format in `data/temperature_data.json`
- Automatically keeps last 1000 entries
- Each entry contains:
  - Received timestamp
  - Device timestamp from ESP8266
  - Array of 6 temperature samples (30 minutes)

## 🎨 Customization

### Change Temperature Units

Edit `index.html` to display Fahrenheit:

```javascript
// In updateStats function
document.getElementById('stat-current').innerHTML = 
    `${(stats.current * 9/5 + 32).toFixed(1)}<span class="stat-unit">°F</span>`;
```

### Adjust Data Retention

Edit `api/receive.php`:

```php
// Keep only last 1000 entries (change this number)
if (count($allData) > 1000) {
    $allData = array_slice($allData, -1000);
}
```

### Change Chart Colors

Edit the Chart.js configuration in `index.html`:

```javascript
borderColor: '#667eea',  // Change line color
backgroundColor: 'rgba(102, 126, 234, 0.1)',  // Change fill color
```

## 📝 Notes

- ESP8266 connects to WiFi only when sending data (hourly)
- Takes temperature samples every 5 minutes
- Sends 30 minutes of data (6 samples) every hour
- Dashboard auto-refreshes every 5 minutes
- Data persists across server restarts

## 🔒 Security Recommendations

For production use:
1. Use HTTPS instead of HTTP
2. Add authentication to API endpoints
3. Implement rate limiting
4. Store data in a proper database
5. Add input validation and sanitization
6. Regular backups of temperature_data.json
