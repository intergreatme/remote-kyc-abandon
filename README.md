# remote-kyc-abandon
A utility to handle user-abandoned transactions in the main Remote-KYC solution.

While users are working through the Remote-KYC solution, status and feedback events are sent through to the customer. Each time one of these events is raised, you add the transaction to the list, which puts a future-based time in the database (you can configure how long you want to set in the future in which a transaction would be seen as abandoned by setting the $abandon_timeout variable. The default is 120 minutes). This future-based time is continually overwritten when each event is raised, continually pushing the abandon time into the future as long as events are being sent through.

Run a cron job using index.php?action=abandon which will iterate through all items in the list, find abandoned items, and dispatch those to the $abandon_dispatch_api URI. The item is then removed from the list to prevent futher abandon calls. If the user resumes the process at any point, a new item is added, resuming the process. It is advisable to only run the cron job every hour using crontab.

Use either a file_get_contents() or invoke cURL to call the abandon service to add, remove, or run the abandon function.

You should call index.php?action=add&txId={UUID}&originTxId={UUID} in your application code each time you receive a status or feedback event.

You should call index.php?action=remove&txId{UUID}&&originTxId={UUID} in your application code when you receive a completion api event. This will remove the item from the list.

Youc an call index.php?action=list to view the transactions that are currently in the list.

# Requirements
Redis, or SQLite as a fallback
PHP

The solution attempts to first connect to redis, or falls back to SQLite. It is suggested to use Redis (turn off disk persistance).
