# tina4php-env
Environment support for your PHP project

An .env will be created for you when the system runs for the first
time. If you specify an environment variable on your OS called ENVIRONMENT then .env.ENVIRONMENT will be loaded instead.

```bash
[Section]           <-- Group section
MY_VAR=Test         <-- Example declaration, no quotes required or escaping, quotes will be treated as part of the variable
# A commment        <-- This is a comment
[Another Section]
VERSION=1.0.0
```