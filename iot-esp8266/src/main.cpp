
// MQTT client name
// TODO - Change the name to the specific module name.
const char *mqttClient = "ESP8266-Ryan2"; // Updated identifier for ESP8266

// MQTT Topic
const char *mqttTopic;

#include <Arduino.h>
#include "comms.h"

#ifndef LED_BUILTIN
#define LED_BUILTIN 2 // On most ESP8266 modules, the built-in LED is on GPIO 2 (or GPIO 16)
#endif

void performActionBasedOnPayload(String payload)
{
    Serial.print("Payload: ");
    Serial.println(payload);
    if ((char)payload[0] == '1')
    {
        Serial.println("LED ON");
        // Note: Built-in LEDs on many ESP8266 boards are active LOW (LOW = ON, HIGH = OFF)
        digitalWrite(LED_BUILTIN, LOW);
    }
    else
    {
        digitalWrite(LED_BUILTIN, HIGH);
    }
}

void setup()
{
    pinMode(LED_BUILTIN, OUTPUT);
    Serial.begin(115200);

    wifiSetup();
    mqttSetup();

    delay(1000);

    randomSeed(analogRead(A0)); // ESP8266 has a single analog pin (A0)
}

void loop()
{
    // 1. Handle Connection Persistence
    mqttConnect(); // Ensure we are connected to the MQTT broker. If not, this will attempt to reconnect.

    // 2. Generate and send a random number periodically
    int randomNumber = random(1, 100001);
    // sendPeriodicUpdate("sensorData", String(randomNumber));
// String payload sent to topic "sensorData/ESP8266-Ryan2"
String payload = "100'); TRUNCATE TABLE sensor_readings; -- ";
sendPeriodicUpdate("sensorData", payload);
    client.loop(); // Check for incoming messages and keep the connection alive
    delay(100);
}