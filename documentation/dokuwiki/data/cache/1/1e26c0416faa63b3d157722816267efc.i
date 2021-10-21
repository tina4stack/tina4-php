a:32:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"header";i:1;a:3:{i:0;s:24:"Basic Website with Tina4";i:1;i:1;i:2;i:1;}i:2;i:1;}i:2;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:3;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:1;}i:4;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:136:"Tina4 already has Twig template engine built in so creating a website is one of the easiest things you can do.
Start off by creating an ";}i:2;i:41;}i:5;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:177;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:10:"index.twig";}i:2;i:179;}i:7;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:189;}i:8;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:13:" file in the ";}i:2;i:191;}i:9;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:204;}i:10;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:13:"src/templates";}i:2;i:206;}i:11;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:219;}i:12;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:36:" folder.  If you hit up the default ";}i:2;i:221;}i:13;a:3:{i:0;s:7:"acronym";i:1;a:1:{i:0;s:3:"URL";}i:2;i:257;}i:14;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:1:" ";}i:2;i:260;}i:15;a:3:{i:0;s:12:"externallink";i:1;a:2:{i:0;s:21:"http://localhost:7145";i:1;N;}i:2;i:261;}i:16;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:32:" then you will see a blank page.";}i:2;i:282;}i:17;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:314;}i:18;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:314;}i:19;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:28:"You can then flesh out your ";}i:2;i:316;}i:20;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:344;}i:21;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:10:"index.twig";}i:2;i:346;}i:22;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:356;}i:23;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:17:" file with valid ";}i:2;i:358;}i:24;a:3:{i:0;s:7:"acronym";i:1;a:1:{i:0;s:4:"HTML";}i:2;i:375;}i:25;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:122:".  The beauty of Twig is you can include other files, for example a header or footer.  The example below illustrates this.";}i:2;i:379;}i:26;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:501;}i:27;a:3:{i:0;s:4:"code";i:1;a:3:{i:0;s:181:"
<!DOCTYPE html>
<html>
  {%include "snippets/header.twig"%}
<body>
  <h1>This is a Heading</h1>
  <p>This is a paragraph.</p>
  {%include "snippets/footer.twig"%}
</body>
</html>

";i:1;s:4:"twig";i:2;s:24:"src/templates/index.twig";}i:2;i:508;}i:28;a:3:{i:0;s:4:"code";i:1;a:3:{i:0;s:42:"
<head>
<title>Page Title</title>
</head>
";i:1;s:4:"twig";i:2;s:34:"src/templates/snippets/header.twig";}i:2;i:734;}i:29;a:3:{i:0;s:4:"code";i:1;a:3:{i:0;s:60:"
<footer>
  <a href="https://tina4.com">Tina4</a>
</footer>
";i:1;s:4:"twig";i:2;s:34:"src/templates/snippets/footer.twig";}i:2;i:831;}i:30;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:940;}i:31;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;i:940;}}