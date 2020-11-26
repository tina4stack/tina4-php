# How to make a webpage - landing page 
## Introduction

A website is made up of web pages, which is a collection of information and relevant content which is displayed to a user on a web browser. Tina4 uses Twig template engine as it is fast, flexible and secure.

Go to the [Twig documentation](https://twig.symfony.com/doc/3.x/) page if you would like to familiarize yourself with Twig. It is important to note that when developing, it is recommended to break down your project into small components. This is done for flexibility for any changes required in future. 

After you have installed Tina4, the configuration for Twig is passed by the Tina4php function running on the index file. All your web pages must be stored in the src/templates directory.

<div align="center" alt="Web page location">
    <img src="images/website.png">
</div>

As an example we will be making a basic landing page with a header, body and footer. 

For further information on factors to consider when designing your site, please look at [Fit Small Business](https://fitsmallbusiness.com/how-to-create-a-landing-page/) or [Blogspot](https://blog.hubspot.com/marketing/how-to-create-a-landing-page), alternatively you can research many design ideas ad concepts for your page. 

Follow the instructions below to get your web page - landing page started...  

### Step 1 - Create index file 

In your project folder, you will need to create an index.twig file in the src / templates directory . This will be the homepage that is loaded when your website is visited. 

You can create this file in your IDE tool by going to the templates directory and right clicking on the folder, select new and then select File. 

<div align="center" alt="Create index Twig file">
    <img src="images/createindex.png">
</div>

Name the file index.twig 
<div align="center" alt="Name file">
    <img src="images/createindex1.png">
</div>

Please note that there are different ways you can create this file, but the main concern is that the name of the file must be "index" and the format ".twig".

Your index.twig file must have the basic HTML elements of a page as below:

```html
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Your Website Title</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Stylesheet and Bootstrap -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/stylesheet.css">

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.16.0/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</head>

<body>

    <div class="container-fluid text-center">
        <!-- 
            Twig Templates included here
         -->
    </div>

</body>

</html>
```
### Step 2 - Create templates

Now that we have our index.twig template, we can create more templates which will be the content for our page. Our page will have a header, body and footer.  

#### Create header 

In the src / templates directory, create another twig file which will be the header of your page. You can name the twig file "header".

Your header.twig file must include all the content which you want in the heading section:

```html
<div class="row">
    <div class="col bg-dark text-white">
        <h1>Welcome...</h1>
        <h2>This is my website</h2>
    </div>
</div>
```

#### Create body 

In the src / templates directory, create another twig file which will be the header of your page. You can name the twig file "body".

Your body.twig file must include all the content which you want in the body section:

```html
<div class=row">
    <div class="col-5 mx-auto">
        <div class="row">
                <div class="col-6">
                    <h1>I made this with Tina4</h1>
                </div>
                <div class="col-6">
                    <h2>Its really easy</h2>
                    <p>Cant believe it!!!</p>
                </div>
        </div>
</div>
```

#### Create footer

In the src / templates directory, create another twig file which will be the header of your page. You can name the twig file "footer".

### Step 3 - Include templates in index

## Conclusion

You have just created a landing page for your website using Tina4. Please feel free to reach out to the developers if you have any questions or suggestions. 

Continue to [how do i](/tutorials/howdoi.md) or go back to  [install ide tool](/installation/install-ide.md)