# Antlr


```bash
antlr4 \
    -o D:\dokuwiki\lib\plugins\combo\ComboStrap\PageSqlParser \
    -package ComboStrap\PageSqlParser \
    -Dlanguage=PHP \
    -lib D:/dokuwiki/lib/plugins/combo/grammar \
    D:/dokuwiki/lib/plugins/combo/grammar\PageSql.g4
```

In the generator configuration in Idea:
  * Output directory: `D:\dokuwiki\lib\plugins\combo\`
  * Package: `ComboStrap\PageSqlParser`
  * Language: `PHP`
  * Lib (not yet used): `D:\dokuwiki\lib\plugins\combo\grammar`
