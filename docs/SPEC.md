BILLIARD SaaS + IoT AUTOMATION PLATFORM

MASTER TECHNICAL SPECIFICATION FOR CLAUDE CODE

You are the lead software architect, senior full-stack engineer, DevOps engineer, database engineer, security engineer and IoT engineer for this project.

Your task is to BUILD A REAL PRODUCTION-READY SYSTEM, not a demo, prototype or toy project.

The system will be commercially used by multiple independent billiard hall owners. It must be designed to operate continuously for years, support multiple clients, isolate every client’s data, control billiard-table lights through ESP32 devices, run on Android tablets, store session photos on the owner’s hosting, and send reports through Telegram.

Do not skip architecture, security, error handling, offline behavior, logging, backups, migrations or testing.

⸻

1. BUSINESS MODEL

The platform belongs to the platform owner.

The platform owner rents access to the software to billiard-hall owners through a subscription/license model.

The platform owner does NOT manage the client’s billiard business.

The platform owner only manages:

* client accounts
* subscription/license status
* subscription expiration
* number of allowed branches
* optionally other limits
* platform infrastructure
* system updates
* technical administration

The client manages everything inside their own account:

* branches
* billiard tables
* table prices
* working hours
* employees/users
* tablets
* ESP32 devices
* sessions
* reports
* Telegram bot configuration
* billiard-hall settings

The client may have multiple branches.

Example:

CLIENT A

* Branch 1
* Branch 2
* Branch 3
* …
* Branch N

The number of allowed branches is controlled by the platform owner through the client’s license.

⸻

2. VERY IMPORTANT MULTI-TENANT RULE

This is a MULTI-TENANT SaaS system.

Every client is a completely isolated tenant.

CLIENT A must NEVER be able to access:

* CLIENT B’s branches
* CLIENT B’s tables
* CLIENT B’s sessions
* CLIENT B’s photos
* CLIENT B’s devices
* CLIENT B’s Telegram configuration
* CLIENT B’s users
* CLIENT B’s reports
* CLIENT B’s subscription information

Do not rely only on frontend filtering.

Tenant isolation MUST be enforced on the backend/database authorization layer.

Every tenant-owned entity must have tenant ownership.

Recommended conceptual structure:

tenant
├── users
├── branches
├── tables
├── devices
├── sessions
├── session_photos
├── payments/session_records
├── reports
├── telegram_config
└── settings

Never trust tenantId supplied by the client.

Resolve tenant identity from the authenticated user/device/session on the server.

⸻

3. PLATFORM OWNER / SUPER ADMIN

Create a separate Super Admin area.

The Super Admin can:

* create client
* suspend client
* activate client
* deactivate client
* extend subscription
* set subscription expiration
* record manual payment
* choose payment method
* set branch limit
* set table limit if this feature is enabled
* set device limit if this feature is enabled
* set user limit if this feature is enabled
* see client status
* see subscription status
* see remaining days
* see basic system/device health
* see audit logs
* manage platform settings

The platform owner does NOT configure the client’s tables or daily business operations unless explicitly required for support.

⸻

4. CLIENT SUBSCRIPTION MODEL

Clients pay the platform owner manually.

No online payment gateway is required in the first version.

Payment can be:

* cash
* bank/card transfer
* other manually recorded payment

The Super Admin records the payment manually.

Example:

Client:
Ali Billiard

Amount:
500,000 UZS

Payment method:
CASH

Subscription:
30 days

Branch limit:
2

After activation:

status = ACTIVE

When expiration is approaching:

5 days before expiration:
send/display warning.

Example:

“Your system subscription expires in 5 days.”

When expiration is reached:

status = EXPIRED

The client must not be able to use the management system normally.

IMPORTANT:

Subscription expiration MUST NOT delete client data.

All data remains stored.

After the client pays again, Super Admin can extend the subscription.

Example:

current expiration:
2026-10-31

add 30 days:

new expiration:
2026-11-30

Support both:

* extending from current expiry if still active
* extending from current date if already expired

Create a complete subscription history.

⸻

5. CLIENT BRANCH LIMIT

The client can create branches themselves.

However, they cannot exceed the number of branches allowed by their license.

Example:

license branchLimit = 2

Client currently has:

Branch 1
Branch 2

If they try to create Branch 3:

show:

“Your branch limit has been reached.”

The Super Admin can increase the limit.

Example:

2 -> 5

The client immediately becomes able to create additional branches.

Do NOT make the platform owner manually create each branch.

⸻

6. CLIENT ADMIN PANEL

The client admin panel must contain:

Dashboard

Show:

* active sessions
* available tables
* today’s sessions
* today’s revenue/session total
* total playing time
* unpaid sessions
* device status
* branch summary

Branches

Client can:

* create branch
* edit branch
* disable branch
* view branch
* configure working hours

Tables

For every branch:

* create table
* edit table
* disable table
* assign device
* configure price
* view current status

Example:

TABLE 01

Price:
20,000 UZS / hour

Device:
ESP32-001

Status:
ONLINE

Pricing

Support configurable pricing.

At minimum:

price per hour

Design the database so future pricing models can be added without rewriting the system.

Working Hours

Client can configure:

* opening time
* closing time
* days of week
* optional holiday/closed days

Users

Allow client owner to create additional staff users.

Example roles:

OWNER
MANAGER
OPERATOR

Do not force every employee to share the owner’s password.

Devices

Client can see:

* device ID
* branch
* table
* online/offline
* last heartbeat
* firmware version
* connection status

Sessions

Client can see:

* session ID
* branch
* table
* start time
* end time
* selected duration
* price
* customer photo
* payment/session status
* device status
* audit history

Reports

Daily/monthly reports:

* sessions
* playing hours
* table utilization
* revenue/session amount
* unpaid sessions
* branch statistics

⸻

7. TABLET SYSTEM

Each billiard branch has an Android tablet.

The tablet is a dedicated kiosk device.

The tablet must remain inside the billiard application.

Users must not be able to normally:

* open Chrome
* open YouTube
* open Android Settings
* exit the application
* access other applications

Use Android kiosk/lock-task architecture where possible.

The tablet should automatically launch the billiard application after reboot.

The app should recover automatically after crashes/reboots.

⸻

8. CUSTOMER SESSION FLOW

The exact customer flow is:

STEP 1

Customer arrives.

Tablet displays all tables.

Example:

TABLE 1 - AVAILABLE
TABLE 2 - BUSY
TABLE 3 - AVAILABLE
TABLE 4 - AVAILABLE

STEP 2

Customer selects an available table.

STEP 3

Customer selects duration.

Example:

30 minutes
1 hour
2 hours
etc.

STEP 4

System calculates the session amount.

STEP 5

Tablet activates its FRONT CAMERA.

Important:

This camera is ONLY the tablet camera.

The billiard hall’s separate CCTV/cash camera is NOT part of this system.

STEP 6

Tablet displays:

“Please look at the camera.”

Use face detection to determine whether a face is visible and sufficiently positioned.

Do NOT implement unnecessary facial recognition/identity matching.

The requirement is to capture a customer photo when a face is properly visible.

STEP 7

Capture ONE customer photo.

Do not continuously record video.

STEP 8

Upload the photo securely to the platform server/storage.

STEP 9

Create session.

Example:

sessionId
tenantId
branchId
tableId
deviceId
startAt
endAt
duration
price
photoId
status

STEP 10

Send START command to the corresponding ESP32.

STEP 11

ESP32 turns the table light ON.

STEP 12

Tablet displays countdown timer.

⸻

9. IMPORTANT CCTV SEPARATION

There are TWO DIFFERENT CAMERA SYSTEMS.

CAMERA A — TABLET CAMERA

This belongs to this platform.

Purpose:

* capture customer photo at session start

The photo is stored on the platform’s hosting/storage.

CAMERA B — CASH/CCTV CAMERA

This belongs to the billiard hall.

Purpose:

* record the cash register
* record customer putting cash into the register
* general CCTV

THIS CAMERA IS NOT PART OF OUR SYSTEM.

Do NOT:

* connect to it
* request its RTSP stream
* upload its video
* store its video
* control it
* integrate with its DVR/NVR
* depend on it for payment verification

The billiard hall manages its own CCTV server/NVR independently.

Our system knows NOTHING about the cash camera.

This separation is mandatory.

⸻

10. PAYMENT MODEL INSIDE THE BILLIARD HALL

The system does NOT automatically verify the physical cash payment.

The customer selects:

table + duration.

The system calculates the amount.

The customer pays cash separately to the hall’s cashier/cash register.

The cash CCTV independently records the payment process.

The platform only records the session information and customer photo.

Do NOT implement fake “cash detected by camera” functionality.

Do NOT claim that the platform verified payment through CCTV.

If a payment status field is required, make it manually configurable by authorized client staff.

⸻

11. SESSION TIMER

When a session starts:

Example:

Start:
14:00

End:
15:00

The system must prevent normal customers from changing the active session.

While active:

* table is locked
* tablet cannot start another session on that table
* ESP32 light remains ON
* countdown runs

At 5 minutes remaining:

trigger warning.

⸻

12. FIVE-MINUTE WARNING

At:

remaining time <= 5 minutes

the system should trigger:

1. audio warning
2. table light flashing three times

Example audio:

“1-stol, sizda 5 daqiqa vaqtingiz qoldi.”

The exact language must be configurable in the future.

Important:

The audio system can be implemented separately from the ESP32 if required.

Design an event system so the warning can trigger reliably.

⸻

13. SESSION END

At session expiration:

1. session status becomes COMPLETED
2. ESP32 turns table light OFF
3. table becomes AVAILABLE
4. tablet refreshes table status
5. session is stored permanently
6. audit event is recorded

Do not rely only on the browser timer.

The backend must store authoritative start/end timestamps.

The ESP32 must also have local fail-safe timing.

⸻

14. INTERNET FAILURE / OFFLINE MODE

This is critical.

The system must continue safely if the internet temporarily fails.

When ESP32 receives:

START

it should receive enough information to operate safely.

Example:

sessionId
startTimestamp
endTimestamp
tableId

ESP32 stores the active session state locally.

If internet connection disappears:

* light remains ON until configured end time
* ESP32 continues its local timer
* at end time it turns OFF
* it does NOT remain ON forever

When connection returns:

ESP32 synchronizes state with server.

Implement heartbeat.

Example:

ESP32 -> server:

deviceId
timestamp
firmwareVersion
currentState
currentSessionId

The server should show:

ONLINE
or
OFFLINE

based on heartbeat timeout.

⸻

15. ESP32 ARCHITECTURE

Use ESP32 as the table controller.

Recommended conceptual architecture:

Internet/Network
|
Wi-Fi Router
|
ESP32
|
Relay/Driver/Contactor
|
Table Light

Each table should preferably have its own ESP32.

Example:

ESP32-001 -> Table 1
ESP32-002 -> Table 2
ESP32-003 -> Table 3

etc.

Every ESP32 must have a unique device identity.

Example:

deviceId:
ESP32-001-A8F4

Do not identify devices only by IP address.

⸻

16. ESP32 COMMUNICATION

Primary communication:

Wi-Fi

Recommended protocol:

MQTT or another reliable device messaging protocol.

Design the system so commands are authenticated.

Example:

platform
->
MQTT broker
->
ESP32

Commands:

START_SESSION
STOP_SESSION
WARNING
SYNC
PING
CONFIG_UPDATE

Do not expose unauthenticated device commands to the local network.

Every device must authenticate.

⸻

17. DEVICE PAIRING

New ESP32 should not automatically belong to a client.

Use secure pairing.

Example:

ESP32 displays/creates pairing code:

482719

Client Admin:

Devices
-> Add Device
-> enter pairing code
-> choose branch
-> choose table
-> confirm

Then:

deviceId
is permanently associated with:

tenantId
branchId
tableId

until explicitly unpaired.

A device belonging to Client A must never be usable by Client B without a secure re-pairing process.

⸻

18. DEVICE SECURITY

Never trust:

tenantId
branchId
tableId

sent by an arbitrary device.

The server must resolve ownership from the authenticated device identity.

Implement:

* device credentials
* secure tokens/certificates where appropriate
* command authorization
* heartbeat
* firmware version
* last seen
* audit events

⸻

19. ELECTRICAL HARDWARE

Do not connect ESP32 GPIO directly to 220V.

Recommended conceptual chain:

ESP32
->
driver/optocoupler
->
relay or contactor
->
220V lighting circuit

Use appropriate:

* power supply
* fuse/MCB
* terminal blocks
* enclosure
* DIN rail components
* electrical protection

The exact relay/contactor rating must be calculated from the actual lamp load.

Electrical mains wiring must be performed according to local electrical safety requirements by a qualified person.

⸻

20. TABLET <-> ESP32

Primary communication architecture should be:

Tablet
->
Backend
->
MQTT/device service
->
ESP32

Do NOT make the system depend exclusively on:

Tablet -> ESP32 direct communication.

The tablet can communicate through the backend.

USB may be supported for service/debugging if useful, but it should NOT be the primary production communication method.

⸻

21. DATABASE

Use a production-grade relational database such as PostgreSQL if supported by the hosting architecture.

Design normalized tables/entities.

At minimum:

users
tenants
subscriptions
subscription_payments
branches
tables
devices
device_pairings
sessions
session_photos
pricing
working_hours
telegram_integrations
audit_logs
notifications
system_settings

Recommended additional entities:

refresh_tokens / sessions
device_heartbeats
subscription_events
branches_settings
table_settings
failed_jobs
notification_logs

Every tenant-owned entity must have tenant ownership.

Use foreign keys and database constraints.

Do not depend solely on application code for relational integrity.

⸻

22. PHOTO STORAGE

Do NOT store customer photos directly inside database blobs unless there is a strong reason.

Store files in hosting/object storage.

Database stores:

photoId
sessionId
tenantId
storagePath
mimeType
size
createdAt
deletedAt

Directory/object structure should isolate tenants.

Example:

/uploads/tenants/{tenantId}/sessions/{sessionId}/customer-photo.jpg

Prevent path traversal and unauthorized direct access.

Prefer private storage with authenticated access rather than public image URLs.

⸻

23. PHOTO RETENTION

The system must support deletion.

Client admin may delete the session photo after the business need has ended, subject to configured permissions.

Record deletion in audit log.

Do not silently delete without trace.

Because customer photos are personal data, implement:

* access control
* secure storage
* retention policy
* deletion
* audit trail
* privacy notice/configuration

Do not implement facial recognition unless explicitly required later.

⸻

24. TELEGRAM BOT

Each client can connect/configure their own Telegram bot.

The bot must only expose that client’s data.

Example daily report:

TODAY’S REPORT

Branch:
Central

Sessions:
37

Total playing time:
29h 20m

Session amount:
740,000 UZS

Table 1:
6h
120,000 UZS

Table 2:
4h
80,000 UZS

etc.

The client may optionally receive session photo information.

Do not use Telegram as the primary storage system.

Photos remain in platform storage.

Telegram is a notification/reporting channel.

⸻

25. HOSTMASTER DEPLOYMENT

The intended production environment is Hostmaster hosting.

Before implementing deployment:

INSPECT THE AVAILABLE HOSTMASTER ENVIRONMENT.

Determine whether it supports:

* PHP
* Node.js
* Python
* PostgreSQL/MySQL
* background processes
* cron jobs
* WebSocket
* MQTT broker
* SSL
* file storage
* environment variables

Do NOT assume unsupported services are available.

If shared hosting cannot safely run MQTT/background services, design the architecture so the supported components remain on Hostmaster and only a technically necessary external/VPS service is proposed.

However, do not introduce external infrastructure unnecessarily.

The user’s requirement is that the main application, database/files and Telegram integration should operate through their hosting where technically supported.

⸻

26. BACKEND

Build a clean REST API or equivalent backend.

Recommended modules:

/auth
/super-admin
/clients
/subscriptions
/branches
/tables
/devices
/sessions
/photos
/reports
/telegram
/notifications
/audit
/health

Use strict authorization middleware.

Example:

authenticate()
authorizeRole()
resolveTenant()
checkSubscription()
validateResourceOwnership()

Do not duplicate authorization logic randomly throughout controllers.

Centralize it.

⸻

27. AUTHENTICATION

Implement secure authentication.

Super Admin:
separate role and permissions.

Client Owner:
tenant-scoped.

Client Manager:
tenant-scoped.

Client Operator:
tenant-scoped.

Device:
device authentication, not normal user authentication.

Tablet:
device/session authentication.

Use:

* secure password hashing
* secure session/token handling
* refresh token rotation where applicable
* rate limiting
* account lockout/risk controls
* HTTPS only
* secure cookies where applicable
* CSRF protection where relevant

Never store plaintext passwords.

⸻

28. AUTHORIZATION

Roles:

SUPER_ADMIN

CLIENT_OWNER

CLIENT_MANAGER

CLIENT_OPERATOR

DEVICE

TABLET

Define permissions explicitly.

Example:

CLIENT_OPERATOR:

* view tables
* create session
* view sessions

CLIENT_MANAGER:

* reports
* users
* pricing
* tables

CLIENT_OWNER:

* full tenant administration

SUPER_ADMIN:

* platform administration

Never use frontend role checks as the only security layer.

⸻

29. SUBSCRIPTION MIDDLEWARE

Every client request that requires an active subscription must pass through subscription validation.

Possible states:

ACTIVE
EXPIRING_SOON
EXPIRED
SUSPENDED

When expired:

allow only necessary restricted actions such as:

* login
* subscription status
* contact/payment instructions
* possibly data export

Do not allow normal business operation.

Do not delete data.

⸻

30. AUDIT LOG

Record important actions.

Examples:

SUPER_ADMIN:

* client created
* subscription activated
* subscription extended
* client suspended

CLIENT:

* branch created
* table created
* price changed
* device paired
* session created
* session completed
* photo deleted
* user created

DEVICE:

* device paired
* heartbeat
* command acknowledged
* error

Store:

actor
actorType
tenantId
action
entityType
entityId
timestamp
metadata

Avoid storing unnecessary sensitive information.

⸻

31. RELIABILITY

Implement:

* global error handling
* structured logging
* health endpoint
* database backup strategy
* migration system
* retry mechanisms
* idempotency where required
* graceful recovery
* device reconnect logic
* job queue/retry if background jobs are used

Do not allow duplicate session creation because of double-clicks or network retries.

Use idempotency keys or equivalent where appropriate.

⸻

32. CONCURRENCY

This is critical.

Two people must not be able to start two simultaneous sessions on the same table.

Use backend/database-level transaction and locking strategy.

Example:

TABLE 1:
AVAILABLE

Request A -> starts session

Request B -> arrives at same time

Only ONE request may succeed.

The other must receive:

“Table is no longer available.”

Do not solve this only in JavaScript frontend.

⸻

33. SESSION STATE MACHINE

Use explicit states.

Example:

AVAILABLE
RESERVED
STARTING
ACTIVE
WARNING
COMPLETING
COMPLETED
CANCELLED
FAILED

Do not use random booleans like:

isPlaying
isFinished
isStarted
isStopped

as the only state mechanism.

Use a well-defined state machine.

⸻

34. DEVICE COMMAND RELIABILITY

Every command should have:

commandId
deviceId
sessionId
createdAt
expiresAt
status

Possible status:

PENDING
SENT
ACKNOWLEDGED
FAILED
EXPIRED

ESP32 should acknowledge important commands.

Example:

SERVER:
START_SESSION command #ABC123

ESP32:
ACK #ABC123

Server:
command status = ACKNOWLEDGED

If ACK does not arrive:
retry according to policy.

Do not blindly send commands repeatedly.

⸻

35. FAIL-SAFE

If server says:

END at 15:30

ESP32 must locally know that end time.

If MQTT disconnects:

ESP32 continues safely.

At 15:30:

light OFF.

If the device reboots:

it should recover its local state and query the server for authoritative state.

Avoid a situation where a reboot causes the light to remain permanently ON.

⸻

36. FRONTEND

Create responsive web admin interface.

Super Admin UI:
desktop/tablet friendly.

Client Admin:
desktop/tablet/mobile friendly.

Tablet Customer UI:
large touch targets
minimal UI
fullscreen kiosk design
high readability.

Do not overload the tablet UI with administrative features.

⸻

37. TABLET DEVICE REGISTRATION

Tablet must have a unique device identity.

Example:

TABLET-CLIENTA-BRANCH1-001

Use secure pairing.

Client admin:

Devices
-> Add Tablet
-> Pairing Code
-> Branch selection
-> Save

Tablet cannot simply enter another tenantId manually.

⸻

38. TABLET OFFLINE BEHAVIOR

The tablet should cache enough information to display:

* table list
* table availability
* basic pricing

However, starting a new session should be carefully controlled because the backend is authoritative.

If network is unavailable:

display:

“Connection unavailable. Please wait.”

Do not create unverified duplicate sessions.

For active sessions, the tablet should continue displaying countdown using the stored session end timestamp.

⸻

39. SERVER TIME

Do not rely on the user’s device clock for business-critical session calculations.

Use server timestamps.

Use UTC internally.

Convert to client/branch timezone for display.

The branch timezone must be configurable.

⸻

40. NOTIFICATIONS

Implement notification service.

Examples:

Subscription expires in 5 days.

Subscription expires in 3 days.

Subscription expires tomorrow.

Subscription expired.

Device offline.

Device back online.

Session warning.

Use database-backed notification logs.

Do not send duplicate notifications unnecessarily.

⸻

41. SUPER ADMIN DASHBOARD

Show:

Total clients
Active clients
Expiring clients
Expired clients
Suspended clients

Revenue recorded from manual subscription payments

Device health summary

System health

Recent audit events

Do NOT expose unnecessary customer personal data.

⸻

42. CLIENT DASHBOARD

Show only the client’s own tenant data.

Example:

Today:
37 sessions

Playing:
5

Available:
3

Today’s session amount:
740,000 UZS

Unpaid:
2

Device status:
7 online
1 offline

⸻

43. SECURITY TESTING

Before declaring the project finished, test:

1. Client A cannot access Client B.
2. Client A cannot access Client B by changing URL.
3. Client A cannot access Client B by modifying API parameters.
4. Client A cannot register Client B’s device.
5. Expired client cannot start sessions.
6. Expired client data remains intact.
7. Branch limit cannot be bypassed through API.
8. Table limit cannot be bypassed through API.
9. Duplicate session requests cannot create duplicate sessions.
10. Unauthorized device commands are rejected.
11. Unauthorized photo access is rejected.
12. Deleted photos are no longer accessible.
13. Passwords are never logged.
14. Secrets are never committed to Git.
15. Telegram credentials are protected.

⸻

44. DATABASE BACKUPS

Implement a backup strategy.

At minimum:

* automated database backups
* backup retention
* ability to restore
* backup verification/documentation

Do not consider “we have hosting” as a backup strategy.

⸻

45. ENVIRONMENT VARIABLES

Never hardcode:

* database passwords
* JWT secrets
* Telegram bot tokens
* MQTT credentials
* storage secrets
* admin passwords
* API keys

Use environment variables / secure configuration.

Provide:

.env.example

without real secrets.

⸻

46. PROJECT STRUCTURE

Use a clean modular architecture.

Do not create one enormous file containing the entire application.

Separate:

frontend
backend
database
device firmware
tablet app
shared types
documentation

Conceptually:

/apps
/web-admin
/tablet
/api

/devices
/esp32

/packages
/shared-types
/validation
/protocol

/infrastructure
/database
/deployment
/mqtt

/docs
architecture.md
api.md
device-protocol.md
deployment.md
security.md
testing.md

Adapt the exact structure to the selected technology stack.

⸻

47. TECHNOLOGY SELECTION

Before writing large amounts of code:

1. Inspect the existing project.
2. Inspect available Hostmaster constraints.
3. Choose technologies that are realistically deployable.
4. Explain the choices in ARCHITECTURE.md.
5. Avoid unnecessary complexity.

Preferred architecture:

Frontend:
React / Next.js or another stable production framework.

Backend:
Node.js/NestJS/Express OR another robust backend supported by hosting.

Database:
PostgreSQL preferred.

IoT:
ESP32 firmware in C++/PlatformIO or ESP-IDF.

Protocol:
MQTT if infrastructure supports it; otherwise design a secure WebSocket/HTTPS fallback.

Android tablet:
A dedicated Android application or reliable kiosk-capable web/PWA architecture depending on hardware requirements.

Do not blindly use technologies just because they are popular.

Choose what is actually deployable on the target infrastructure.

⸻

48. DO NOT USE LOCALSTORAGE AS THE MAIN DATABASE

localStorage may be used only for:

* temporary UI preferences
* non-critical cache
* kiosk state cache where appropriate

Do NOT store the actual business database in localStorage.

The authoritative data must be on the server/database.

⸻

49. DATA OWNERSHIP

Every resource must have an explicit ownership chain.

Example:

Session
-> Table
-> Branch
-> Tenant

Photo
-> Session
-> Table
-> Branch
-> Tenant

Device
-> Table
-> Branch
-> Tenant

The backend must verify this ownership chain.

⸻

50. ERROR HANDLING

Never show raw backend errors to customers.

Create user-friendly messages.

Log technical details securely on the server.

Example:

User:

“Something went wrong. Please try again.”

Server log:

SESSION_START_FAILED
reason:
DEVICE_OFFLINE

Do not expose stack traces.

⸻

51. API DOCUMENTATION

Create OpenAPI/Swagger documentation if practical.

Document:

authentication
clients
subscriptions
branches
tables
sessions
devices
photos
reports
telegram
health

Document request/response schemas.

⸻

52. DEVICE PROTOCOL DOCUMENTATION

Create a dedicated document:

docs/device-protocol.md

Document:

device registration
pairing
authentication
heartbeat
START_SESSION
STOP_SESSION
WARNING
SYNC
ACK
reconnect
offline behavior
firmware update strategy

⸻

53. ESP32 FIRMWARE

The firmware must include:

* Wi-Fi provisioning
* secure device identity
* server/MQTT authentication
* reconnect logic
* heartbeat
* command handling
* local session state
* persistent state
* watchdog
* safe boot behavior
* relay control
* command acknowledgement
* firmware version
* diagnostics

Implement a watchdog so the device can recover from unexpected software hangs.

On boot:

1. initialize hardware safely
2. ensure output state is safe
3. load persistent session state
4. connect network
5. authenticate
6. synchronize with server
7. apply authoritative state
8. start heartbeat

⸻

54. HARDWARE SAFETY

The software must assume the relay/contactor is controlling potentially dangerous voltage.

Never expose 220V directly to ESP32 GPIO.

Provide hardware fail-safe behavior.

Use proper electrical isolation.

The final electrical design must be reviewed by a qualified electrician.

⸻

55. FIRMWARE UPDATE

Design for future OTA firmware updates.

Each device reports:

firmwareVersion

Later the platform owner can publish:

firmware version 1.1.0

Devices can be updated securely.

Do not implement insecure arbitrary firmware download.

⸻

56. MONITORING

Provide:

GET /health

and if practical:

GET /health/db
GET /health/storage
GET /health/messaging

Super Admin should be able to see basic system health.

⸻

57. RATE LIMITING

Protect:

* login
* pairing
* API
* photo upload
* device registration

from abuse.

⸻

58. PHOTO UPLOAD SECURITY

Validate:

* MIME type
* file extension
* file size
* image dimensions

Do not trust filename.

Generate server-side filenames.

Prevent executable files from being uploaded as images.

⸻

59. PRIVACY

The customer photo is personal data.

Design the system so:

* only authorized client users can view it
* other tenants cannot access it
* it is not publicly accessible
* deletion is supported
* retention can be configured
* access is logged

The client should be responsible for informing customers about the photo capture according to applicable local law.

Do not implement facial recognition unless separately required.

⸻

60. TESTING

Create automated tests where practical.

At minimum:

Unit tests:

* subscription calculations
* branch limits
* session calculations
* pricing
* authorization
* state transitions

Integration tests:

* database
* API
* tenant isolation
* session creation
* device commands

End-to-end tests:

* client login
* branch creation
* table creation
* tablet pairing
* session start
* session completion

IoT tests:

* command
* ACK
* reconnect
* offline timer
* reboot recovery

⸻

61. TEST SCENARIOS

Test this exact scenario:

CLIENT A has 2 branches.

CLIENT B has 5 branches.

A must never see B.

CLIENT A creates Branch 1 and Branch 2.

Attempt Branch 3.

Expected:
DENIED.

Super Admin changes limit from 2 to 3.

Attempt Branch 3 again.

Expected:
SUCCESS.

Then create Table 1.

Pair ESP32.

Start a 10-minute test session.

Verify:

photo captured
session created
ESP32 receives command
light turns on
timer runs
5-minute warning triggers
session ends
light turns off
table becomes available

Disconnect internet during active session.

Expected:
ESP32 continues safely and turns light off at end.

Reconnect.

Expected:
state synchronizes.

⸻

62. DO NOT BUILD FAKE FEATURES

Never create fake implementations such as:

* fake payment verification
* fake CCTV integration
* fake face recognition
* fake device online status
* fake MQTT
* fake storage

If a real external dependency is unavailable, clearly mark the integration point and implement a proper adapter/interface.

Do not pretend a feature works when it doesn’t.

⸻

63. DEVELOPMENT METHOD

DO NOT generate the entire project blindly in one pass.

Follow this process:

PHASE 1:
Inspect project and hosting environment.

PHASE 2:
Create architecture documentation.

PHASE 3:
Create database schema and migrations.

PHASE 4:
Implement authentication and multi-tenancy.

PHASE 5:
Implement Super Admin.

PHASE 6:
Implement Client Admin.

PHASE 7:
Implement branch/table/session system.

PHASE 8:
Implement tablet application.

PHASE 9:
Implement photo capture/upload.

PHASE 10:
Implement ESP32 communication.

PHASE 11:
Implement firmware.

PHASE 12:
Implement Telegram.

PHASE 13:
Implement subscription expiration/notifications.

PHASE 14:
Implement monitoring/logging/backups.

PHASE 15:
Security testing.

PHASE 16:
Production deployment.

After each phase:

* run tests
* fix errors
* update documentation
* do not continue while critical errors remain

⸻

64. GIT

Use Git properly.

Create meaningful commits.

Do not commit:

.env
secrets
API keys
passwords
Telegram bot tokens
MQTT credentials

Create:

.env.example

⸻

65. FINAL PRODUCTION REQUIREMENT

The finished project must include:

1. Source code
2. Database migrations
3. Environment configuration example
4. Deployment instructions
5. Super Admin documentation
6. Client Admin documentation
7. Tablet setup documentation
8. ESP32 flashing instructions
9. Device pairing instructions
10. Device protocol documentation
11. API documentation
12. Backup instructions
13. Security documentation
14. Testing documentation
15. Troubleshooting guide

⸻

66. FINAL ACCEPTANCE CRITERIA

Do not declare the project complete until these work:

SUPER ADMIN:

* create client
* activate client
* record manual payment
* set subscription duration
* set branch limit
* suspend/activate client
* see expiration
* see audit logs

CLIENT:

* login
* create branches within license
* create tables
* set prices
* configure working hours
* add staff
* pair tablet
* pair ESP32
* view devices
* view sessions
* view reports
* configure Telegram

TABLET:

* kiosk mode
* show tables
* select table
* select duration
* calculate price
* open front camera
* detect face
* capture one photo
* upload photo
* start session
* show timer
* show warning
* finish session

ESP32:

* authenticate
* connect Wi-Fi
* heartbeat
* receive session
* turn light ON
* warning flash
* turn light OFF
* survive internet interruption
* recover after reboot
* synchronize after reconnect

SERVER:

* multi-tenant isolation
* subscription enforcement
* photo storage
* audit logs
* Telegram
* backups
* monitoring
* error handling

⸻

67. CRITICAL BUSINESS RULE

The platform owner sells ACCESS TO THE SOFTWARE.

The platform owner does NOT own or operate the client’s billiard halls.

The client controls their own billiard business.

The only platform-level restrictions are the license/subscription restrictions configured by the Super Admin.

The client can use their licensed system across the number of branches allowed by their subscription.

⸻

68. IMPORTANT CAMERA RULE

Remember this permanently during implementation:

TABLET CAMERA:
YES — part of this system.
Purpose: capture one customer face/photo before session.

CASH CCTV CAMERA:
NO — NOT part of this system.
Purpose: client’s independent cash/CCTV recording.
Storage: client’s own DVR/NVR/server.
Our backend: NO ACCESS.

Never accidentally implement CCTV integration.

⸻

69. IMPORTANT PAYMENT RULE

PLATFORM SUBSCRIPTION PAYMENT:

Client pays the platform owner manually by cash/bank/card.
Super Admin records it manually.
No payment gateway required in first version.

BILLIARD SESSION PAYMENT:

Customer pays the billiard hall separately.
The platform does NOT verify the cash through CCTV.
The cash CCTV belongs to the client and remains independent.

⸻

70. FIRST ACTION

Before writing implementation code:

1. Inspect the existing repository.
2. Inspect package files.
3. Inspect current hosting/deployment configuration.
4. Identify the current stack.
5. Identify what can run on Hostmaster.
6. Produce:

docs/ARCHITECTURE.md
docs/DATABASE.md
docs/SECURITY.md
docs/DEVICE_PROTOCOL.md
docs/DEPLOYMENT.md

7. Show the proposed architecture and identify any contradictions.
8. Do not invent missing infrastructure.
9. Then begin implementation phase-by-phase.

If an architectural decision is required, choose the simplest production-grade solution that satisfies the requirements.

Do not reduce this project to a demo.

The final objective is a real commercial SaaS + IoT system that can be sold to many independent billiard hall owners and operated reliably for years.