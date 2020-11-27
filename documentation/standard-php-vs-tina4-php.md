#PHP vs Tina4 PHP
######This is not another framework!
#### Background
So perhaps you are an experienced PHP developer, or a someone who used to do it once, long ago, and are doing your best to try and get back into it; that would be me. To approach something new is always daunting, especially when it seems like a wall of learning and you are struggling to understand so much jargon, like why the **Postman** walks with a **swagger**, is failing to deliver the **package** to the **Composer**. He keeps ending up at the door of some girl called **Tina**, who keeps complaing about the **framework** of her **Windows**, and tells him **Mac** will sort it out.
#### Task at hand
So the challenge was simple, build a standard PHP powered webpage, that uses a form to write to a database. Then do the same thing using Tina4 and compare the experiences. As an added bonus we also needed to include Bootstrap as a layout system to make it all look pretty.
#### Environment - Standard
While not the actual project, if you are starting from stratch, setting up the environment to work in, can actually be quite challenging, mostly because you know so little, you miss the important stuff, and if something does go wrong, the error messaging is not always easy to understand, even with the help of Mr Google. 

Essentially you need [PHP 7.1 > greater to be installed](http://www.php.net) on your machine. However heading over to the downloads section, of a technically orientated site, can be a little daunting with all the available options. I went with the Windows downloads, choosing the latest Zip package that was thread safe. Expand that into a folder of your choosing, (C:\php\) for me, and technically you are set to go, almost.

You are going to need to configure the php.ini file. (**Hint**: Make a copy of the file, before you stick your fingers into it.)
1) You need to search for **include_path** and **extensions_dir**, and uncomment (remove the ;) the lines appropriate for your system.
2) You need to search for **extensions=** and uncomment those that are appropriate (in my newbness I uncommented everything, and then recommented some when I hit errors)
3) Remember to save

Finally you are ready to go, almost. As we are calling the program from a Windows command line, you need to [set the path environment variable](https://docs.alfresco.com/4.2/tasks/fot-addpath.html) for the folder where you expanded the php, (C:\php\) for me.

We are now ready to go, for real. 

1) Create a folder where you want to build your first project. Create a file index.php. 
2) Using the command line **navigate to that folder**, and use this command to start up a PHP webserver environment.

    ```c:\MyProjectFolder>php -S localhost:8080```
3) Open up your web browser and type 

    ```localhost:8080```

(**Tip:** If it does a google search on the line, then there is something wrong with your setup. Modern browsers often allow searches from the url line, especially if it does not understand what you are wanting, like trying to reach a webserver that did not start)    

If you get an error, follow it through, if you get nothing, then you reached your blank index.php file and it is time to start building. Well done.

#### Standard PHP

So easiest is to start with the HTML, less chances for things to go wrong. Purposefully put in radio buttons and dropdowns, to add a little complexity. 

    <form name="BasicForm" method="post">
        <p>First Name: <input name="First" type="text" placeholder="Please enter your first name"> </p>
        <p>Last Name: <input name="Last" type="text" placeholder="Please enter your last name"> </p>
        <p>Male <input name="Gender" type="radio" value="M"> Female <input name="Gender" type="radio" value="F"> </p>
        <p>What meal type would you
            <select name="Meal">
                <option value"Standard">No special Meal requirements</option>
                <option value"Vegetarian">Vegetarian - no meat</option>
                <option value"Vegan">Vegan - no animal products</option>
                <option value"Kosher">Kosher</option>
                <option value"Halaal">Halaal</option>
            </select>
        </p>
        <p><input name="Submit" type="submit"></p>
    </form>

Use [DB Browser for SQLite](https://sqlitebrowser.org/) to create the neccessary database. The creation SQL should look something like this.
```
CREATE TABLE "User" (
	"ID"	INTEGER PRIMARY KEY AUTOINCREMENT UNIQUE,
	"FirstName"	TEXT,
	"LastName"	TEXT,
	"Gender"	TEXT,
	"Meal"	TEXT
);
```
Next start adding the php. Make sure that the post variables are coming through, no point chasing your tail later when the database entry is not working. 

```var_dump($_POST);``` 

Once you have your POST array, capture it in a variable and create your database connection.
```php
    // Put the POST global array into a variable
    $myPost = $_POST;
    // Open a database connection
    $dsn = "sqlite:BDForm.db";
    $myPDO = new PDO($dsn,'','', array(PDO::ATTR_PERSISTENT => true));
```
Build the insert SQL string
 ```
$theSQL = "INSERT INTO User(FirstName, LastName, Gender, Meal) VALUES('" . $myPost['First'] . "', '" . $myPost['Last'] . "', '" . $myPost['Gender'] . "', '" . $myPost['Meal'] . "')";
```
Ok, confession: it is not always that easy. All those "."'; soon turn into @#$%^ when you are having a bad day. (**Tip:** print the string to the screen and ensure it reads the way it should, or alternatively put it into the Execute SQL box in the DB Browser.)

```echo $theSQL;```

Now insert into the database, and using DB Broswer check that it is there, in the way you expected it to be. Get Coffee!

Finally it is a good exercise to build a table, showing what you have entered. Great to get instant feedback that it actually worked. Here is a mix of HTML and PHP that gets the job done. It is important to note that this pulls the entire table, into an arrary (this is the part that determines how it returns the data PDO::FETCH_ASSOC)

    <table>
        <tr>
            <th>UserID</th><th>First Name</th><th>Last Name</th><th>Gender</th><th>Meal Type</th>
        </tr>
        
            <?php
            // Create the table of existing Users
            // Open a database connection
            $dsn = "sqlite:BDForm.db";
            $myPDO = new PDO($dsn,'','');
            // Select all the rows from the table, ararnged as an array with field names as index
            $result = $myPDO->query("SELECT * FROM User", PDO::FETCH_ASSOC );
            // Loop through each row of the table
            foreach ( $result as $user ) {
                echo '<tr>';
                // Loop through each field of the row
                foreach ( $user as $key => $value ) {
                    echo "<td>$value</td>";
                }
                echo '</tr>';
            }
            ?>

    </table>

So now you should be able to build any database powered website, ok maybe a little practice needed. However should give a great platform to tackle the project from a Tina4 perspective.

#### Environment - Tina4

The Tina4 environment starts with the Standard Environment, but you need to [install Composer on your computer](https://getcomposer.org/download/). Then in each project folder you wish to setup, you need to run this line, at the project folder command line, which will effectively install Tina4 for each project you undertake. It also takes care of automatic updates of Tina4 when a new version is released.

```c:\myProjectFolder>composer require andrevanzuydam/tina4php```

Create an index.php file in the root of your project folder and insert the following code, and Tina4 is ready to roll - really! (**Tip:** It is super important to read the note in the code below. Placement is everything.)

```
<?php
// Get Composer to load Tina4 and then get her started.
require "vendor/autoload.php";

/* This is so super important. 
Any code you need to add to the index.php file needs to go below the autoload above (otherwise Tina4 has not loaded yet). 
Any code you need to add to this index.php file needs to go above the Tina4 creation below (otherwise Tina4 is running and anything below her is only executed after she has done her thing.) */

// Start Tina4
echo new \Tina4\Tina4Php();
```

Again, start up the web server, again important to start it in the project folder you are working with.

 ```c:\MyProjectFolder>php -S localhost:8080 index.php```
 
 If you can see the lady, then Tina4 is up and running. She is telling you 'not found' because you have an empty website and need to start with something. 
 
### Building in Tina4
 
So in fairness this is where I got completely stuck and had to tap out and ask for help. If you are a seasoned coder, perhaps a quick look into the Tina4 code, nicely broken into small files, and you could have been on your way. I needed a little more structure to hang onto, and a push in a couple of directions.

The greatest assitance was understanding the logic flow, and then putting all the pieces in place to get that to operate, essentially a four step process. Firstly build a **router**, to catch the incoming data if applicable, and direct it toward the correct **template**. The template then uses various **services** related to each **object**. Thankfully the appropiately named folders (read app for services) have been put in place for you, and shh don't tell anyone but you do not need to tell Tina where to find the different parts, provided you put them in the correct folders.

Due to our needing to work with the database, it makes sense to setup a database at the outset. Might as well make it global in the index.php file. (**Tip:** Remember that super important notice about placement in the index.php file)
```
// Establish a global Database Connection
global $DBA;
$DBA = new \Tina4\DataSQLite3("BDForm.db");
```

So first create an index.twig file in the templates folder, and might as well put the form code in it from earlier. Now at least your website should be showing something.

To start we create a route file, to receive the POST and save the information to the database.
```
<?php

\Tina4\Post::add("/submit-form", function (\Tina4\Response $response, \Tina4\Request $request) {

    // Create a person object, map the POST data into the correct fields.
    $person = new Person();
    $person->firstName = $request->params["First"];
    $person->lastName = $request->params["Last"];
    $person->gender = $request->params["Gender"];
    $person->meal = $request->params["Meal"];
    // This single line of code writes the record to the database.
    $person->save();

    // Go back to the home page.
   \Tina4\redirect("/");

});
```

To now print out the User table, we add these lines of code to the index.twig file, just below the submission form.

```
   <!-- Get the PersonService to give us the persons on the database in an HTML format -->
   {% set htmloutput = Tina4.call("PersonService", "getPeople", []) %}
   {{ htmloutput | raw }}
```

Naturally we need to create a PersonService.php file in the app folder with a method called getPeople. 
 ```
class PersonService extends \Tina4\Data
{
    function getPeople()
    {
        // Get all the people from the database returned as an array
        $people = (new Person())->select("*", 1000)->asArray();
        //a renderTemplate returns html, which feeds into the twig files
        return Tina4\renderTemplate("people.twig", ["persons" => $people]);
    }
}
```
Naturally to get that working we need a Person class object in the objects folder. (**Tip:** It is important to notice the naming convention, as this has great bearing on optimisation later.)
```
class Person extends \Tina4\ORM
{
    public $id; //id
    public $firstName; //first_name
    public $lastName; //last_name
    public $gender; //gender
    public $meal; // meal - Meal Type required

}
```

And finally one needs the the people.twig file to return the users into the index.twig file. (**Notice** the twig loop that walks through the user array)
```
<h1>ALL THE PEOPLE</h1>
<table>
    <tr><th>First Name</th><th>Last Name</th><th>Gender</th><th>Meal Type</th></tr>

    {% for human in persons %}
        <tr>
            <td>{{ human.firstName }}</td>
            <td>{{ human.lastName }}</td>
            <td>{{ human.gender }}</td>
            <td>{{ human.meal }}</td>
        </tr>
    {% endfor %}

</table>
```

### Bootstrapping

So to get the page looking pretty, we need to add Bootstrap, a free front-end design framework to make your site responsive and pretty, easily.

The best way to [get started is perhaps this page](https://www.w3schools.com/bootstrap4/bootstrap_get_started.asp). Which gets the following code into the head of your index.php page, and explains what they all do.
```
    <!--These are the lines for getting Bootstrap onto the website -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.16.0/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js"></script>
```
Thereafter, one just has to add the specific class names to allow Bootstrap to format your page. It is important to note that Bootstrap uses 'containers' as an outside wrapper. While there are some more normal classes like 'col' there are also some specialised ones like the 'jumbotron' seen below, or perhaps a carousel.
```
<div class="container">
    <div class="col">
        <footer class="jumbotron">
            <h4 class="text-center">This is a Tina4 Bootstrapped exercise</h4>
        </footer>
    </div>
</div>
```
### Bootstrap Tips
If you are completely new to Bootstrap, I would say approach it as follows.
1) Have a basic understanding of CSS, and how it is implemented in HTML. As Bootstrap is effectively doing just the same stuff, but saves you from doing all the hard work. It will also make it easier to understand, and know where to start looking when applying something.
2) Read through the [Full Bootstrap Tutorial](https://www.w3schools.com/bootstrap4/default.asp), as it will give you an understanding of what is available in Bootstrap.
3) When coding a project, implement Bootstrap from the begining, as it almost starts usurping some of the HTML. To retrospectively apply Bootstrap, will actually take longer, at times needing to rewrite sections of code.

### Optimisation

So as we come to the end of the process, a little more learnt than before, some optimisation is required. 

**Database Management:** Helpfully, Tina4 has a database mapper, that pulls the POST array straight into the database. What is important here is that the ```name="fieldName"``` of the input element, needs to map to the ```field_name``` of the database. So the code looks like this, yes just two real lines of code.
```
    // Create a person object, map the POST data into the correct fields.
    $person = new Person($request->params);
    /* Note that by ensuring that the name="inputName" element name maps to field_name on the database
       allows that the next 4 lines are replaced with passing the return $request->params */
        // $person->firstName = $request->params["First"];
        // $person->lastName = $request->params["Last"];
        // $person->gender = $request->params["Gender"];
        // $person->meal = $request->params["Meal"];
    // This single line of code writes the record to the database.
    $person->save();
``` 
 
 **The Bootstrap Viewpoint:** Another optimisation feature took place without me really doing anything, was Bootstrap. It seamlessly just 'fixes' the look and layout of the page. This line of code allows Bootstrap to be responsive, still looking great, even when the screen size changes.
 ```
<meta name="viewport" content="width=device-width, initial-scale=1">
``` 
### Standard vs Tina4

So finally would I take up Tina4, with the learning curve required, to build projects, the answer would be of course. Three main points.

**Database manipulation:** Those two lines of code to take in the POST, and write it into the database were worth gold. As I stated before, trying to code SQL statements, turns to frustration really quickly. Also think about extrapolating the site to numerous pages, the update and delete events on the data, and Tina4 is going to save loads of time. Also imagine if instead of 4 fields there were 10 to 20, which is not unreasonable.

So this is the standard code.
```
$myPost = $_POST; //$_REQUEST vs $_GET or $_POST;

if (!empty($myPost)) {
    $theSQL = "INSERT INTO User(FirstName, LastName, Gender, Meal) VALUES('" . $myPost['First'] . "', '" . $myPost['Last'] . "', '" . $myPost['Gender'] . "', '" . $myPost['Meal'] . "')";
    echo $theSQL;
    $result = $myPDO->query($theSQL);
};
``` 
This is Tina4, where Person is an object that has been defined elsewhere. I like Tina4.
```
    $person = new Person($request->params);
    $person->save();
``` 
**Twig integration:** To be honest, I think I am still a little unsure about the available depth of the Twig integration. However it definitely makes it easier, both from a readability and ease of use perspective, to put together the main business logic.

So this is the standard code. 
```
    $result = $myPDO->query("SELECT * FROM User", PDO::FETCH_ASSOC );
    // Loop through each row of the table
    $count = 0;
    foreach ( $result as $user ) {
        echo '<tr>';
        // Loop through each field of the row
        foreach ( $user as $key => $value ) {
            echo "<td>$value</td>";
        }
        echo '</tr>';
        $count++;
    }
```

Here is Tina4, and I appreciate that there are a couple of lines of code not shown here which are used to call this, but I do enjoy the simplicity.
```
    {% for human in persons %}
        <tr>
            <td>{{ human.firstName }}</td>
            <td>{{ human.lastName }}</td>
            <td>{{ human.gender }}</td>
            <td>{{ human.meal }}</td>
        </tr>
    {% endfor %}
```
**The Great Unknown:** Understanding the benefits I have outlined above, I am also aware that I am scratching the surface, and that the benefits will extend to other parts of database management, and things I have not even thought of yet. I also imagine that as one scales up the development, one will become more grateful for all that Tina4 has to offer.