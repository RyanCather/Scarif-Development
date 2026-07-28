// MQTT client name
// TODO - Change the name to the specific module name.
const char *mqttClient = "ESP32-Ryan"; // This should be unique for each ESP32, e.g: "ESP32_Servo", "ESP32_Piezo", etc

// MQTT Topic
const char *mqttTopic;

#include <Arduino.h>
#include "comms.h"

void performActionBasedOnPayload(String payload)
{
    Serial.print("Payload: ");
    Serial.println(payload);
    if ((char)payload[0] == '1')
    {
        Serial.println("LED ON");
        digitalWrite(LED_BUILTIN, HIGH);
    }
    else
    {
        digitalWrite(LED_BUILTIN, LOW);
    }
}

void setup()
{
    pinMode(LED_BUILTIN, OUTPUT);
    Serial.begin(9600);
    wifiSetup();
    mqttSetup();
    while (!Serial)
    {
        delay(10);
    }
    delay(1000);
}

void loop()
{
    // 1. Handle Connection Persistence
    mqttConnect(); // Ensure we are connected to the MQTT broker. If not, this will attempt to reconnect.

    // 2. Generate and send a random number periodically
    unsigned long now = millis();
    if (now - lastUpdate > updateInterval)
    {
        lastUpdate = now;

        // TODO: Upload data to server as required.
    }

    client.loop(); // Check for incoming messages and keep the connection alive
    delay(100);
}