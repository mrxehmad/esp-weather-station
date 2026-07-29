# Hardware Setup

## Parts

| Qty | Part | Notes |
|---|---|---|
| 1 | ESP8266 board | Wemos D1 Mini / NodeMCU / ESP-12, 4 MB flash |
| 1 | NTC thermistor | 10 kΩ @ 25 °C, B = 3425 |
| 1 | Resistor | 10 kΩ (1% tolerance recommended) |
| 1 | SSD1306 OLED *(optional)* | 128×64 I²C; disabled in firmware by default |

## Wiring

### Thermistor

Voltage divider with the thermistor on the high side:

```
3V3 ───[ NTC 10k ]───┬─── A0
                     │
                  [ 10k ]
                     │
GND ─────────────────┴─── GND
```

- `A0` is the ESP8266's analog pin.
- Keep leads short if the probe is remote — long wires pick up noise.

### Optional OLED (I²C)

| OLED | ESP8266 |
|---|---|
| VCC | 3V3 |
| GND | GND |
| SDA | GPIO14 (D5) |
| SCL | GPIO12 (D6) |

Enable it in firmware: `#define DISPLAY_ENABLED 1`

## Assembly tips

- **Keep the thermistor away from the ESP8266 and voltage regulator.**
  Both self-heat; inside a sealed enclosure they can raise local temperature
  by 20–30 °C. This is the #1 cause of "reads 50 °C at room temperature".
- If the enclosure must be sealed, put the sensor on a short wire outside the
  box, or add small ventilation slots.
- Verify the series resistor is actually 10 kΩ — a wrong value shifts the
  entire temperature curve.

## Power

Any USB 5 V supply (phone charger) works. The node draws ~80 mA average with
WiFi on. No battery support in this firmware variant (it stays awake 24/7).

## First-boot check

After flashing, open the serial monitor at **115200 baud**. You should see:

```
=== 3.0.0 BOOT ===
Boot RTC #1 | WiFi reconn 0
Reset reason: Power on
WiFi: connected IP=10.1.1.11 RSSI=-67 ch=3
HTTP: 200 OK
```

Then open `http://<device-ip>/` in a browser to see the on-device dashboard.
```