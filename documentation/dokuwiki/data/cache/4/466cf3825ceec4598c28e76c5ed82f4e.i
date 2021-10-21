a:46:{i:0;a:3:{i:0;s:14:"document_start";i:1;a:0:{}i:2;i:0;}i:1;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:4:{s:5:"state";i:1;s:10:"attributes";a:2:{s:5:"level";i:1;s:12:"heading_text";s:13:"APIs in Tina4";}s:7:"context";s:7:"outline";s:8:"position";i:1;}i:2;i:1;i:3;s:6:"======";}i:2;i:1;}i:2;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:14:" APIs in Tina4";s:7:"context";N;}i:2;i:3;i:3;s:15:" APIs in Tina4 ";}i:2;i:7;}i:3;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:17:"combo_headingwiki";i:1;a:3:{s:5:"state";i:4;s:10:"attributes";a:1:{s:5:"level";i:1;}s:7:"context";s:7:"outline";}i:2;i:4;i:3;s:7:"======
";}i:2;i:22;}i:4;a:3:{i:0;s:12:"section_open";i:1;a:1:{i:0;i:1;}i:2;i:1;}i:5;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:22;}i:6;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:53:"From inception Tina4 was designed to rapid prototype ";}i:2;i:30;}i:7;a:3:{i:0;s:7:"acronym";i:1;a:1:{i:0;s:3:"API";}i:2;i:83;}i:8;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:104:" end points in conjunction with making SwaggerUI documentation available quickly with annotated routing.";}i:2;i:86;}i:9;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:190;}i:10;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:190;}i:11;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:18:"After running the ";}i:2;i:192;}i:12;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:210;}i:13;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:14:"composer start";}i:2;i:212;}i:14;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:226;}i:15;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:119:" command in your IDE terminal, the Swagger UI will be available when you use the following address in your Web brosers ";}i:2;i:228;}i:16;a:3:{i:0;s:7:"acronym";i:1;a:1:{i:0;s:3:"URL";}i:2;i:347;}i:17;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:13:" address bar:";}i:2;i:350;}i:18;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:364;}i:19;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"url";}}i:2;i:1;i:3;s:10:"<code url>";}i:2;i:364;}i:20;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:33:"
 http://localhost:7145/swagger 
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"url";}}i:2;i:3;i:3;s:33:"
 http://localhost:7145/swagger 
";}i:2;i:374;}i:21;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"url";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:407;}i:22;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:407;}i:23;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:41:"An example of a post end point end point:";}i:2;i:416;}i:24;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:457;}i:25;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:1;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:1;i:3;s:10:"<code php>";}i:2;i:459;}i:26;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:4:{s:5:"state";i:3;s:7:"payload";s:451:"
<?php

/**
* @description This end point allows and upload of a json string and saves it to a file
* @tags users,uploads
*/
\Tina4\Post::add("/api/users/uploads", function (\Tina4\Response $response, \Tina4\Request $request){

    if (!empty($request->data)) {
        file_put_contents("./updates/update.json", serialize($request->data));
        $result = "Ok!";
    } else {
        $result = "Failed!";
    }

    return $response ($result);
});
";s:7:"context";N;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:3;i:3;s:451:"
<?php

/**
* @description This end point allows and upload of a json string and saves it to a file
* @tags users,uploads
*/
\Tina4\Post::add("/api/users/uploads", function (\Tina4\Response $response, \Tina4\Request $request){

    if (!empty($request->data)) {
        file_put_contents("./updates/update.json", serialize($request->data));
        $result = "Ok!";
    } else {
        $result = "Failed!";
    }

    return $response ($result);
});
";}i:2;i:469;}i:27;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_code";i:1;a:2:{s:5:"state";i:4;s:10:"attributes";a:1:{s:4:"type";s:3:"php";}}i:2;i:4;i:3;s:7:"</code>";}i:2;i:920;}i:28;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:12:"wrap_divwrap";i:1;a:2:{i:0;i:1;i:1;s:17:"center round info";}i:2;i:1;i:3;s:24:"<WRAP center round info>";}i:2;i:929;}i:29;a:3:{i:0;s:6:"p_open";i:1;a:0:{}i:2;i:929;}i:30;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:13:"You can also ";}i:2;i:954;}i:31;a:3:{i:0;s:11:"strong_open";i:1;a:0:{}i:2;i:967;}i:32;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:6:"secure";}i:2;i:969;}i:33;a:3:{i:0;s:12:"strong_close";i:1;a:0:{}i:2;i:975;}i:34;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:6:" your ";}i:2;i:977;}i:35;a:3:{i:0;s:7:"acronym";i:1;a:1:{i:0;s:3:"API";}i:2;i:983;}i:36;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:32:" end points by clicking on this ";}i:2;i:986;}i:37;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:3:{s:5:"state";i:1;s:10:"attributes";a:1:{s:3:"ref";s:35:"tina4:tutorials:secure_api_endpoint";}s:7:"context";s:7:"divwrap";}i:2;i:1;i:3;s:37:"[[tina4:tutorials:secure_api_endpoint";}i:2;i:1018;}i:38;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:3:{s:5:"state";i:3;s:7:"payload";s:4:"link";s:7:"context";N;}i:2;i:3;i:3;s:5:"|link";}i:2;i:1055;}i:39;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:10:"combo_link";i:1;a:4:{s:5:"state";i:4;s:10:"attributes";a:1:{s:3:"ref";s:35:"tina4:tutorials:secure_api_endpoint";}s:7:"payload";s:0:"";s:7:"context";s:7:"divwrap";}i:2;i:4;i:3;s:2:"]]";}i:2;i:1060;}i:40;a:3:{i:0;s:5:"cdata";i:1;a:1:{i:0;s:2:". ";}i:2;i:1062;}i:41;a:3:{i:0;s:7:"p_close";i:1;a:0:{}i:2;i:1065;}i:42;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:12:"wrap_divwrap";i:1;a:2:{i:0;i:4;i:1;s:0:"";}i:2;i:4;i:3;s:7:"</WRAP>";}i:2;i:1065;}i:43;a:3:{i:0;s:13:"section_close";i:1;a:0:{}i:2;i:1072;}i:44;a:3:{i:0;s:6:"plugin";i:1;a:4:{i:0;s:15:"combo_analytics";i:1;a:3:{s:10:"attributes";a:7:{s:17:"combo_headingwiki";i:1;s:7:"section";i:1;s:1:"p";i:4;s:6:"strong";i:2;s:10:"combo_code";i:2;s:12:"wrap_divwrap";i:1;s:10:"combo_link";i:1;}s:7:"context";N;s:5:"state";i:5;}i:2;i:5;i:3;s:0:"";}i:2;N;}i:45;a:3:{i:0;s:12:"document_end";i:1;a:0:{}i:2;N;}}