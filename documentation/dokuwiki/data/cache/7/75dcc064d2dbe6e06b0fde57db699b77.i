a:26:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:17:"Posting form data";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:18:" Posting form data";s:7:"context";N;}i:2;i:3;i:3;s:19:" Posting form data ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:7:"======
";}i:2;i:26;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:26;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:96:"You need a form key to post to a Tina4 Post route, in twig this is easy to add to into your form";}i:2;i:34;}i:7;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:131;}i:8;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:4:"html";}}i:2;i:1;i:3;s:11:"<code html>";}i:2;i:131;}i:9;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:124:"
<form action="/post-route" method="post">
   <button>Submit</button>
   {{ "reason for token" | formToken | raw }}
</form>
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:4:"html";}}i:2;i:3;i:3;s:124:"
<form action="/post-route" method="post">
   <button>Submit</button>
   {{ "reason for token" | formToken | raw }}
</form>
";}i:2;i:142;}i:10;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:4:"html";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:266;}i:11;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:266;}i:12;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:39:"In code the token is also available as ";}i:2;i:281;}i:13;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:321;}i:14;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:321;}i:15;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:33:"
$_SESSION["tina4:authToken"]   
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:33:"
$_SESSION["tina4:authToken"]   
";}i:2;i:331;}i:16;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:364;}i:17;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:364;}i:18;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:54:"You can define how load a token lasts in your env file";}i:2;i:379;}i:19;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:434;}i:20;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:434;}i:21;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:26:"
TINA4_TOKEN_MINUTES=5   
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:26:"
TINA4_TOKEN_MINUTES=5   
";}i:2;i:444;}i:22;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:470;}i:23;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:477;}i:24;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:4:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:3;s:10:"combo_code";i:3;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:25;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}