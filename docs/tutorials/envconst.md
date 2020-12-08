<!--
// Tina4 : This Is Not A Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andrevanzuydam@gmail.com
-->
# Create Environment Variables/Constants

## Introduction

When you're doing your magic, you will find that variables and constants are important components. There will be constants, variables and spells that you don't want exposed (e.g. contact numbers, domain manes, email addresses, passwords and URLS).

Tina4 helps you with your safety and security. Once you have [installed Tina4](/installation/install-tina4.md), spun up a webserver and browsed on localhost, an ".env" file will be created in your project directory. 

Never include the ".env" file when uploading or hosting. A magician never reveals their secrets.

### Step 1 - Create label

Open the ".env" file located in the project root directory, with your IDE tool / text editor. Create the label or group section of the variables or constants you want stored securely. The label must be created within square brackets.  For example, if you want to store SMTP settings.

```md
[SMTP Settings]
```
### Step 2 - Declare Variable

Now that the label for the group of variables / constants has been been created, we can declare our set of variables. Escaping and quotes is not required  and quotes will be treated as part of the variable. 

```md
[SMTP Settings]
SMTP_SERVER=smtp.gmail.com
SMTP_USER=youremail@gmail.com
SMTP_PASSWORD=clancyGilroy
SMTP_PORT=465
```

### Step 3 - Save, Close and Restart

Save the variables / constants that you just created. Close your IDE tool and restart the program. This is to ensure that the variables / constants are safely applied throughout your project. 

## Conclusion

You now know how to declare your project environment variables and constants. You will also use this ".env" file for different execution modes (e.g. development, staging, production etc. )

Continue to [create a contact form](/tutorials/contactform.md) or go back to [create a custom webpage - landing page](/tutorials/customwebsite.md).