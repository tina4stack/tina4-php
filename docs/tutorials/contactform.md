<!--
// Tina4 : This Is Not A Framework
// Created with : PHPStorm
// User : andrevanzuydam
// Copyright (C)
// Contact : andrevanzuydam@gmail.com
-->
# Create a contact form 

## Introduction

A contact form gives a user the ability to communicate the website owner. When the user inputs information and clicks the send/submit button, the user and website owner gets an email to notify each other about any questions. 

We will also look at some safety measures to ensure that the website owner does not get spammed. 

Follow the instructions below to create a contact form with Tina4.
  
### Step 1 - Create form template

For this example we will have our contact form within the footer of the page. 

As mentioned in [create a webpage - landing page](/tutorials/website.md), Tina4 uses a base file located in the assets directory, which is used to render your landing page. This base file has a navigation, body and footer.  

We created an "index.twig" file in the src \ templates directory which extends to "base.twig" file located in the src \ assets directory, which looks like this:

```twig
{% extends 'base.twig' %}

{% set title = 'My Test Site' %}
{% set image = 'images/logo.png' %}
{% set description = 'Tina4 helped me create this' %}

{% block navigation  %}
    <nav>
        <a href="Hello">Hello World</a>
        <h1>Welcome...</h1>
        <h2>This is my website</h2>
    </nav>
{% endblock %}

{% block content  %}
    <h3>Content</h3>
    <h3>Some More Content</h3>
{% endblock %}

{% block footer  %}
    <footer class="fixed bottom">
        <div class="col bg-dark text-white fixed-bottom text-center">
            <p class="foot">Copyright ©
                <script>document.write(new Date().getFullYear())</script>  <!-- gets current year -->
                My Test Site. All Rights Reserved
            </p>
        </div>
    </footer>
{% endblock %}
```

We can make a seperate footer template which we can include in the "index.twig" file. You can do this by changing the footer section in your "index.twig" script to this:

```twig
{% block footer  %}
    {% include 'footer.twig' %}
{% endblock %}
```

Now create a "footer.twig" template inside the src \ templates directory.

### Step 2 - Create form elements  

Now we can create all our form elements in the "footer.twig" template. This will be input fields, buttons and alerts. 

```twig
<div class="container">

    <!-- Contact Us -->
    <h2 class="contactHead text-center"><u>Contact Us</u></h2>
    <form id="contactForm" method="post">
    <div class="section justify-content-center">
        <div class="form-group">
            <div class="row">
                <div class="col-md-6">
                    <div class="col">
                        <input id="NAME" type="text" name="NAME" placeholder="Enter your Name" class="form-control" for="NAME" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="col">
                        <input id="SURNAME" type="name" name="SURNAME" placeholder="Enter your Surname" class="form-control" for="SURNAME" required>
                    </div>
                </div>
            </div>
            <div class="row pt-2">
                <div class="col-md-6">
                    <div class="col">
                        <input id="CELNO" type="tel" name="CELNO" placeholder="Enter your contact number" class="form-control" for="CELNO" required>
                    </div>
                </div>
                <div class="col-md-6 ">
                    <div class="col">
                        <input id="EMAIL" type="email" name="EMAIL" placeholder="Enter your email" class="form-control" for="EMAIL" required>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-group">
            <div class="row">
                <div class="col-md-12">
                    <textarea class="form-control" id="MSG" name="MSG" placeholder="Please enter your message here..." rows="3" for="MSG" required></textarea>
                </div>
            </div>
        </div>
        <div class="form-group">
            <button class="btn-primary rounded" value="send" type="submit" name="submit">Send</button>
            <button class="btn-primary rounded" value="reset" type="reset" name="submit">Reset</button>
        </div>

    </div>
    </form>

    <!-- Success & error message -->
    <div id="success_message" style="display:none">
        <h3>Thank You For Your Message</h3>
        <p> We will get back to you soon. </p>
    </div>
    <div id="error_message" style="width:100%; height:100%; display:none; ">
        <h3>Error</h3> Sorry there was an error sending your form. 
    </div>

    <!-- Footer -->
    <div class="row">
        <footer>
            <div class="col bg-dark text-white fixed-bottom text-center">
                <p>Copyright ©
                    <script>document.write(new Date().getFullYear())</script>  <!-- gets current year -->
                    My Test Site. All Rights Reserved
                </p>
            </div>
        </footer>
    </div>

</div>
```  

### Step 2 - Add form validation

Now that we have all the basic elements for our form, we will need to make the information fields required, validate the information, add functionality to the send/rest button, create a mail handler.

In this part it is very important to pay attention to the names, id, type and for attributes of our form elements as this is important for functionality, handling and validation.

#### Make fields required

Add the "required" attribute to your input and text area elements. This will ask the user to fill out fields in the form. This is a good fallback if the Jquery plugin for any reason fails. 

```twig
<!-- Example for input -->
<input id="NAME" type="text" name="NAME" placeholder="Enter your Name" class="form-control" for="NAME" required>

<!-- Example for textarea -->
<textarea class="form-control" id="MSG" name="MSG" placeholder="Please enter your message here..." rows="3" for="MSG" required></textarea>

``` 

#### Add validation

Tina4 uses JQuery validation. It gives you the ability to set validation rules and prompt the user of errors and feedback of what is required. We will create an external JavaScript file where we will include our rules and prompts.

In the src \ assets \ js directory, create a JavaScript file named "form.js".  

<div align="center" alt="Create form Js file">
    <img src="images/form.png">
</div>

Now link your "form.js" file, in the head section, of the "base.twig" located in src \ assets (HINT: You can put it above the "tina4help.js" script)

```twig
    <!-- Form Handler -->
    <script type="text/javascript" src="./js/form.js"></script>
```

Go back to your "form.js" file and insert the JQuery function which will have our rules and messages which must be validated:

```javascript
$(document).ready(function () {
    $("#contactForm").validate({
        rules: {
            NAME: {
                required: true
            },
            SURNAME: {
                required: true
            },
            CELNO: {
                required: true,
                digits: true
            },
            EMAIL: {
                required: true,
                email: true
            },
            MSG: {
                required: true,
            },
        },
        messages: {
            NAME: {
                required: "Please enter your Name"
            },
            SURNAME: {
                required: "Please enter a Contact Surname"
            },
            CELNO: {
                required: "Please enter a Cellphone Number",
                digits: "Only digits may be entered"
            },
            EMAIL: {
                required: "Please enter an Email Address",
                email: "Please enter a valid Email Address ( example@email.com )"
            },
            MSG: {
                required: "Please tell us what we can help you with"
            }
        }
    });
});
``` 

This is how your form will look, prompting the user with the message that was inserted in the JQuery function:

<div align="center" alt="JQuery Validation">
    <img src="images/form1.png">
</div>


### Step 3 - Add mail functionality 

Tina4 uses 

Next we will use JavaScript to make our send/rest work. When the user clicks send, a success or error message will appear. 

### Step 4 - Test contact form

## Conclusion

You have just created a contact form with a handler to ensure emails are sent to both parties, user inputs required fields and spam protection.

Continue to [create a REST API](/tutorials/createapi.md) or go back to [create a custom webpage - landing page](/tutorials/customwebsite.md).