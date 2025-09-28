import serial
import sys
import time

# Replace 'COM6' with your Arduino port
arduino = serial.Serial('COM6', 9600, timeout=1)
time.sleep(2)  # wait for Arduino to reset

if len(sys.argv) > 1:
    command = sys.argv[1]
    print(f"Python script received command: '{command}'")
    print(f"Sending to Arduino: '{command}'")
    arduino.write((command + "\n").encode())
    print("Command sent successfully!")
    print(f"Python script finished - command '{command}' sent to Arduino")
else:
    print("No command provided")

arduino.close()