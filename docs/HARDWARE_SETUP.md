# Hardware Setup Guide

## Required Components

1. **ESP8266 Development Board**
   - NodeMCU v1.0 (recommended)
   - Wemos D1 Mini
   - ESP-12E/F module

2. **Temperature Sensor**
   - NTC Thermistor 10kΩ @ 25°C
   - Beta value: 3425 (verify with datasheet)

3. **Additional Components**
   - 10kΩ resistor (for voltage divider)
   - OLED Display 128x64 SSD1306 (optional)
   - Breadboard and jumper wires
   - USB cable for programming

## Wiring Instructions

### Thermistor Connection
```
ESP8266 A0 ──┬── Thermistor ── 3.3V
             │
             └── 10kΩ Resistor ── GND
```

### OLED Display Connection (Optional)
```
ESP8266         OLED Display
GPIO14 (D5) ──  SDA
GPIO12 (D6) ──  SCL
3.3V        ──  VCC
GND         ──  GND
```

## Power Supply

- USB Power: 5V via micro-USB
- Battery Power: 3.7V LiPo with voltage regulator
- Recommended: Use quality USB power supply (at least 500mA)

## Testing

1. Connect thermistor and resistor
2. Upload test sketch to verify readings
3. Check serial monitor for temperature values
4. Verify readings against known temperature source
