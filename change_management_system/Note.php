1. Fixed the error by assigning expressions to variables before passing to mysqli_stmt_bind_param, as it requires variables passed by reference:

- Created `$log_details` for the log query details parameter
- Created `$notify_message` for the notification message parameter

The error occurred because mysqli_stmt_bind_param expects variables, not expressions like ternary operators or function calls.


2. 