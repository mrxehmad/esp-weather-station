# API Documentation

## Base URL
```
http://YOUR_SERVER_IP/temp-station/api/
```

## Endpoints

### Temperature Data

#### Store Temperature
**POST** `/receive.php`

**Request Body:**
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

#### Get Temperature Data
**GET** `/getData.php?hours=24`

**Query Parameters:**
- `hours` (optional): Number of hours to retrieve (default: 24)
- `limit` (optional): Maximum number of readings (default: 10000)

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
  },
  "filter": {
    "hours": 24,
    "from": "2024-01-30 12:00:00",
    "to": "2024-01-31 12:00:00"
  }
}
```

### User Tracking

#### Store User Data
**POST** `/receive_user.php`

**Request Body:**
```json
{
  "users": [
    {
      "mac": "192.168.4.2",
      "device": "Unknown",
      "email": "user@example.com",
      "phone": "+1234567890",
      "connect_time": 1738368000,
      "duration": 300
    }
  ]
}
```

**Response:**
```json
{
  "status": "success",
  "message": "User data stored",
  "users_received": 1,
  "total_users": 45
}
```

#### Get User Data
**GET** `/getUserData.php?hours=168`

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

## Error Responses

All endpoints return error responses in the following format:

```json
{
  "status": "error",
  "message": "Error description"
}
```

**Common HTTP Status Codes:**
- `200` - Success
- `400` - Bad Request (invalid JSON or missing fields)
- `404` - Not Found (database or resource not found)
- `405` - Method Not Allowed (wrong HTTP method)
- `500` - Internal Server Error (database or server error)
