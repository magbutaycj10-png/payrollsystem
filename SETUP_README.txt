# 🖥 L&N Pharmacy Payroll System - Desktop App Setup
No XAMPP needed. One double-click to open.
(What the system does, how it is tested and how to deploy it online: see README.md.)

---

## 📁 Final Folder Structure

```
C:\PayrollApp\
│
├── launch.vbs          ← Double-click this to start (or use as shortcut)
├── launch.bat          ← The actual launcher (don't delete)
├── stop.bat            ← Run this to stop the server
├── secrets.bat         ← Your database connection details (never uploaded)
├── ca.pem              ← Your Aiven SSL certificate (never uploaded)
│
├── php\                ← Portable PHP (you download this once)
│   ├── php.exe
│   ├── php.ini         ← Copy the php.ini from this folder here
│   └── ext\
│       └── (dll files)
│
├── payroll2\           ← All the app PHP files
│   ├── index.php
│   ├── dashboard.php
│   ├── sql\database.sql  ← the base tables (Step 7)
│   └── ...
│
└── tests\              ← optional: the automatic checks (run-tests.bat)
```

---

## ✅ Step-by-Step Setup

### Step 1 - Create the app folder
Create a folder:  `C:\PayrollApp\`

---

### Step 2 - Copy your app files
Copy this whole project (`payroll2\`, `launch.*`, `stop.bat`, `php.ini`, `secrets.bat.example`, …) into `C:\PayrollApp\`

---

### Step 3 - Download Portable PHP

1. Go to: **https://windows.php.net/download/**
2. Download **PHP 8.2 (or 8.3) - VS16 x64 Thread Safe** → `.zip` file
3. Extract it → rename the folder to `php`
4. Move it to `C:\PayrollApp\php\`
5. Copy the `php.ini` file (from this project) into `C:\PayrollApp\php\php.ini`

> If there's already a `php.ini-production` inside, you can use that instead -
> just make sure these lines are uncommented (remove the `;`):
> ```
> extension=pdo_mysql
> extension=openssl
> extension=mbstring
> ```

---

### Step 4 - Add your Aiven SSL Certificate

1. Go to **https://aiven.io** → your payroll service
2. Click **Connection information** → scroll to the bottom
3. Click **Download CA Certificate** → save as `ca.pem`
4. Place it at: `C:\PayrollApp\ca.pem`  (or `C:\PayrollApp\payroll2\ca.pem`)

`ca.pem` is listed in `.gitignore`, so it never goes to GitHub.

---

### Step 5 - Set your database connection

Nothing about the database is written in the code - host, user and password
all come from `secrets.bat`, which `.gitignore` keeps out of GitHub.

Copy `secrets.bat.example` to `secrets.bat` in the same folder and fill in the
five lines from Aiven → your service → **Connection information**:
```
set DB_HOST=your-service.aivencloud.com
set DB_PORT=your-port
set DB_NAME=defaultdb
set DB_USER=your-user
set DB_PASS=your-password
```
`launch.bat` and `launch-lan.bat` load `secrets.bat` automatically.

---

### Step 6 - Launcher files

`launch.vbs`, `launch.bat` and `stop.bat` must sit directly in `C:\PayrollApp\`
(they were copied with the project in Step 2).

---

### Step 7 - Create the tables (a NEW, empty database only)

Use **DBeaver** (free) or **TablePlus** (free) to connect to the database with the same
details you put in `secrets.bat` (SSL CA: point to your `ca.pem`), then:

1. File → Open File → `payroll2\sql\database.sql`
2. Make sure the right connection and database are selected
3. **Alt + X** (Execute script)

That creates the seven base tables. It is safe to run again (every statement is `CREATE TABLE IF NOT EXISTS`).
Everything else - the sign-in table, leave, daily attendance, the audit trail, signatures - the
application creates by itself the first time a page loads. **Skip this step if you already have a
payroll database**: the update changes no tables.

---

### Step 8 - Create a Desktop Shortcut

1. Right-click `C:\PayrollApp\launch.vbs`
2. Click **Send to → Desktop (create shortcut)**
3. Right-click the new shortcut on your desktop → **Rename** → `L&N Pharmacy Payroll`
4. *(Optional)* Right-click → **Properties** → **Change Icon** → pick any icon `.ico` file

---

## 🌐 Putting it online (GitHub + Render)

1. **Change the admin password first.** Sign in on this PC (`launch.bat`) and
   change it under Settings → Admin Account. The live site refuses the
   default password, and a fresh install forces a change at first sign-in.
2. **Push to GitHub.** `.gitignore` already keeps out everything private:
   `secrets.bat`, `ca.pem`, `backups/` (data snapshots), logs, the agent's
   `config.ini` and the portable PHP folder. Check with `git status` before
   the first commit - none of those should be listed.
3. **On Render**, create a Web Service from the repo (Docker). Under
   **Environment**, add:
   ```
   DB_HOST   DB_PORT   DB_NAME   DB_USER   DB_PASS
   ```
   and the certificate, either as a **Secret File** named `ca.pem`, or as an
   environment variable `DB_SSL_CA_PEM` holding the certificate's text
   (Render's Secret Files are not readable by Apache's user - the environment
   variable is the one that works).
   The Dockerfile already sets `APP_ENV=production`, hides PHP errors and
   versions, and blocks the code-only folders (`includes`, `sql`, `tools`).
4. **In Aiven**, under the service's *Allowed IP addresses*, limit access to
   Render's outbound addresses and this PC's if you can, instead of 0.0.0.0/0.
5. If a password was ever shared or pushed by mistake, **change it in Aiven**
   (Reset password) and update `secrets.bat` and Render.

---

## 🚀 Daily Use

| Action | How |
|---|---|
| **Open the system** | Double-click the shortcut on your desktop |
| **System opens in browser** | Automatically at `http://localhost:8765` |
| **Stop the system** | Double-click `stop.bat` (or just close the browser - PHP stops automatically when idle) |
| **Already running?** | Double-clicking again just reopens the browser tab |
| **13th month pay** | Payroll Process → 13th Month Pay (see README.md) |
| **Check the numbers** | Open `tests\dbeaver_checks.sql` in DBeaver on your database and press Alt+X - read-only queries that flag anything that does not add up |

---

## ❓ Troubleshooting

| Problem | Fix |
|---|---|
| "PHP not found" error | Make sure `php.exe` is at `C:\PayrollApp\php\php.exe` |
| Blank page or 500 error | Check `C:\PayrollApp\payroll_error.log` for the error message |
| "The database connection is not configured" | Fill in `secrets.bat`, then run `stop.bat` and `launch.bat` again |
| Can't connect to database | Check the details in `secrets.bat` and that `ca.pem` is present |
| Port already in use | Run `stop.bat` first, then launch again |
| SSL error | Make sure `extension=openssl` is uncommented in `php\php.ini` |
| "Too many failed sign-ins" | Wait 15 minutes - wrong passwords are slowed down on purpose |

---

## 🔐 Login

URL: `http://localhost:8765`
Sign in with the admin email and password set during installation.
A new install starts with the default password and asks you to change it
right away; sessions sign out after 8 hours without activity.
