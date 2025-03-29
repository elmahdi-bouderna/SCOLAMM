# IoT RFID Attendance System

A comprehensive classroom attendance tracking system using RFID technology, ESP32 microcontrollers, and web technologies.

## Project Overview

This system allows real-time tracking of student attendance using RFID cards. When students scan their RFID cards, their attendance is automatically recorded and displayed on an LCD screen and in a web-based dashboard. The system supports multiple classroom sessions, different user roles (students, professors, parents), and provides detailed attendance reporting.

![System Architecture](https://example.com/system-architecture.png)

## Features

- **Real-time attendance tracking** via RFID cards
- **LCD display** showing attendance status
- **Multiple user roles**: Students, Professors, Administrators, Parents
- **Instant feedback** via LCD screen and buzzer
- **Web dashboard** for monitoring attendance
- **Statistical reports** for attendance patterns
- **Automatic absent marking** for non-attendees
- **Parent portal** for monitoring student attendance
- **Email notifications** for absences (optional)

## Hardware Components

- ESP32 microcontroller
- MFRC522 RFID reader module
- 16x2 I2C LCD display (0x27 address)
- Buzzer for audio feedback
- RFID cards/tags (13.56MHz)
- Micro USB power supply
- Jumper wires and breadboard

## Software Technologies

- **Backend**: Node.js, Express, WebSockets, MySQL
- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap, Chart.js
- **Database**: MySQL
- **IoT Device**: Arduino IDE with ESP32 libraries

## Setup Instructions

### 1. Database Setup

1. Install XAMPP from [https://www.apachefriends.org/](https://www.apachefriends.org/)
2. Start Apache and MySQL services
3. Access phpMyAdmin at `http://localhost/phpmyadmin/`
4. Create a new database named `iot`
5. Import the database schema from `database/iot.sql`

### 2. Server Setup

1. Install Node.js from [https://nodejs.org/](https://nodejs.org/)
2. Clone this repository
3. Navigate to the project directory
4. Install dependencies:
   ```bash
   npm install express ws mysql2 dotenv
