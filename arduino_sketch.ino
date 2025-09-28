#include <Servo.h>

// --- Pin definitions ---
const int servoPin = 9;  // Servo motor pin

// --- 7-segment display pins ---
const int segPins[8] = {2, 3, 4, 5, 6, 7, 8, 10};  // a,b,c,d,e,f,g,dp
const int digitPins[4] = {11, 12, 13, A0};  // digit1,digit2,digit3,digit4

// --- Variables ---
Servo myServo;
unsigned long interval = 30000;  // 30 seconds default
unsigned long lastMillis = 0;
int feedCounter = 0;  // Debug counter
bool isFeeding = false;  // Prevent simultaneous feeding

// --- 7-segment patterns (0-9) ---
const byte digitPatterns[10] = {
  B11111100,  // 0
  B01100000,  // 1
  B11011010,  // 2
  B11110010,  // 3
  B01100110,  // 4
  B10110110,  // 5
  B10111110,  // 6
  B11100000,  // 7
  B11111110,  // 8
  B11110110   // 9
};

void setup() {
  Serial.begin(9600);
  
  // Initialize 7-segment display pins
  for (int s=0; s<8; s++) pinMode(segPins[s], OUTPUT);
  for (int d=0; d<4; d++) pinMode(digitPins[d], OUTPUT);
  
  // Initialize all digits off
  for (int d=0; d<4; d++) digitalWrite(digitPins[d], HIGH);
  // Initialize segments off
  for (int s=0; s<8; s++) digitalWrite(segPins[s], LOW);

  // Initialize servo to 0° position and keep it there
  myServo.attach(servoPin);
  myServo.write(0);   // Set to 0° position
  delay(1000);        // Wait for servo to settle
  // Keep servo attached to maintain position

  lastMillis = millis();
}

void loop() {
  unsigned long currentMillis = millis();
  
  // --- Automatic feeding timer DISABLED ---
  // Arduino will only feed when commanded via serial (FEED_NOW or AUTO_FEED)
  // No automatic feeding from Arduino's internal timer

  // --- Serial command handling ---
  if (Serial.available() > 0) {
    String command = Serial.readStringUntil('\n');
    command.trim();
    Serial.println("=== RECEIVED COMMAND: " + command + " ===");

    if (command == "FEED_NOW") {
      if (isFeeding) {
        Serial.println("FEED_NOW BLOCKED - Already feeding in progress");
        return;
      }
      
      isFeeding = true;
      feedCounter++;
      Serial.println("MANUAL FEED #" + String(feedCounter) + " - DIRECT SERVO CONTROL");
      
      // Manual feed: direct servo control (separate from automatic)
      myServo.attach(servoPin);
      delay(100);
      myServo.write(180);   // move to feeding position (180°)
      delay(1000);          // wait 1s for feeding
      myServo.write(0);     // return to rest position (0°)
      delay(500);           // wait 0.5s
      myServo.detach();     // detach to prevent jitter
      
      Serial.println("DONE - Manual feed #" + String(feedCounter) + " completed (1x)");
      isFeeding = false;
      
    } else if (command == "AUTO_FEED") {
      if (isFeeding) {
        Serial.println("AUTO_FEED BLOCKED - Already feeding in progress");
        return;
      }
      
      isFeeding = true;
      Serial.println("AUTOMATIC FEED - USING feedFish FUNCTION");
      Serial.println("DEBUG: About to call feedFish(1)");
      feedFish(1);  // feed 1 time (automatic)
      Serial.println("DEBUG: feedFish(1) completed");
      isFeeding = false;
    } else if (command.startsWith("SET_INTERVAL")) {
      int newInterval = command.substring(command.indexOf(":")+1).toInt();
      if (newInterval > 0) {
        interval = (unsigned long)newInterval;
        lastMillis = millis(); // restart countdown on update
        Serial.print("Interval updated to (ms): "); Serial.println(interval);
      }
    }
  }

  // --- Display countdown (seconds) ---
  long remainingMs = (long)interval - (long)(currentMillis - lastMillis);
  if (remainingMs < 0) remainingMs = 0;
  unsigned long remaining = (unsigned long)(remainingMs / 1000UL);
  if (remaining > 9999UL) remaining = 9999UL; // clamp to 4 digits

  displayNumber((int)remaining);
}

// --- Feed function (for automatic feeding only) ---
void feedFish(int times) {
  Serial.print("DEBUG: feedFish called with times = ");
  Serial.println(times);
  
  // Reattach servo only when feeding
  myServo.attach(servoPin);
  delay(100);  // Small delay for servo to initialize
  
  // Automatic feed: single cycle - same motion as manual feed
  for (int i=0; i<times; i++) {
    Serial.print("DEBUG: Feeding cycle ");
    Serial.print(i+1);
    Serial.print(" of ");
    Serial.println(times);
    
    myServo.write(180);   // move to feeding position (180°)
    delay(1000);          // wait 1s for feeding
    myServo.write(0);     // return to rest position (0°)
    delay(500);           // wait 0.5s between cycles
  }
  
  // Return to rest position and detach to prevent jitter
  myServo.write(0);       // Rest position (0°)
  delay(500);             // Wait for servo to settle
  myServo.detach();       // Detach to prevent electrical interference
  
  Serial.println("DEBUG: feedFish completed");
}

// --- 7-segment display ---
void displayNumber(int num) {
  int digits[4];
  for (int i=3; i>=0; i--) {
    digits[i] = num % 10;
    num /= 10;
  }
  
  for (int d=0; d<4; d++) {
    // Turn off all digits
    for (int i=0; i<4; i++) digitalWrite(digitPins[i], HIGH);
    
    // Set segments for current digit
    byte pattern = digitPatterns[digits[d]];
    for (int s=0; s<8; s++) {
      digitalWrite(segPins[s], bitRead(pattern, 7-s));
    }
    
    // Turn on current digit
    digitalWrite(digitPins[d], LOW);
    delay(2);  // Display time per digit
  }
}