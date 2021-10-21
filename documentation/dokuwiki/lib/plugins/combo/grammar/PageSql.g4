//https://github.com/antlr/grammars-v4/

grammar PageSql;


/**
 Lexer (ie token)
 https://github.com/antlr/antlr4/blob/master/doc/lexer-rules.md
*/
SCOL:      ';';
DOT:       '.';
LPAREN:  '(';
RPAREN: ')';
LSQUARE:  '[';
RSQUARE: ']';
LCURLY: '{';
RCURLY: '}';
COMMA:     ',';
BITWISEXOR : '^';
DOLLAR : '$';
EQUAL:    '=';
STAR:      '*';
PLUS:      '+';
MINUS:     '-';
TILDE:     '~';
PIPE2:     '||';
DIV:       '/';
MOD:       '%';
LT2:       '<<';
GT2:       '>>';
AMP:       '&';
PIPE:      '|';
QUESTION:  '?';
LESS_THAN:        '<';
LESS_THAN_OR_EQUAL:     '<=';
GREATER_THAN:        '>';
GREATER_THAN_OR_EQUAL:     '>=';
EQ:        '==';
NOT_EQUAL:   '!=';
NOT_EQ2:   '<>';

/**
 * Key word
*/
AND:               A N D;
AS:                A S;
ASC:               A S C;
BETWEEN:           B E T W E E N;
BY:                B Y;
DESC:              D E S C;
ESCAPE:            E S C A P E;
FALSE:             F A L S E;
FROM:              F R O M;
GLOB:              G L O B;
IN:                I N;
IS:                I S;
ISNULL:            I S N U L L;
LIKE:              L I K E;
LIMIT:             L I M I T;
NOT:               N O T;
NOTNULL:           N O T N U L L;
NOW:               N O W;
NULL:              N U L L;
OR:                O R;
ORDER:             O R D E R;
SELECT:            S E L E C T;
TRUE:              T R U E;
WHERE:             W H E R E;
RANDOM:            R A N D O M;

// Function
DATE:            D A T E;
DATETIME:        D A T E T I M E;
functionNames: DATE | DATETIME;

// Tables
PAGES:            P A G E S;
BACKLINKS:        B A C K L I N K S;
tableNames: DATE | DATETIME;

// LITERALS
fragment Letter : 'a'..'z' | 'A'..'Z';

fragment HexDigit : 'a'..'f' | 'A'..'F';

fragment Digit : '0'..'9' ;

fragment Exponent : ('e' | 'E') ( PLUS|MINUS )? (Digit)+;

fragment RegexComponent :
    'a'..'z' | 'A'..'Z' | '0'..'9' | '_'
    | PLUS | STAR | QUESTION | MINUS | DOT
    | LPAREN | RPAREN | LSQUARE | RSQUARE | LCURLY | RCURLY
    | BITWISEXOR | PIPE | DOLLAR | '!'
    ;

// https://www.sqlite.org/lang_expr.html
// A string constant is formed by enclosing the string in single quotes ('). A single quote within the string can be encoded by putting two single quotes in a row - as in Pascal. C-style escapes using the backslash character are not supported because they are not standard SQL.
StringLiteral :
    ( '\'' ( ~'\'' | '\'\'')* '\''
    | '"' ( ~('"') )* '"'
    )+
    ;

CharSetLiteral
    : StringLiteral
    | '0' 'X' (HexDigit|Digit)+
    ;

IntegralLiteral
    : (Digit)+ ('L' | 'S' | 'Y')
    ;

Number
    : (Digit)+ ( DOT (Digit)* (Exponent)? | Exponent)?
    ;

NumberLiteral
    : Number ('D' | 'B' 'D')
    ;

ByteLengthLiteral
    : (Digit)+ ('b' | 'B' | 'k' | 'K' | 'm' | 'M' | 'g' | 'G')
    ;


/**
 * Sql also does not permit
 * to start with a number
 * (just ot have no conflict with a NUMERIC_LITERAL)
*/
SqlName: (Letter | Digit) (Letter | Digit | '_')*;


/**
* Space are for human (discard)
*/
SPACES: [ \u000B\t\r\n] -> channel(HIDDEN);



/**
 * Fragment rules does not result in tokens visible to the parser.
 * They aid in the recognition of tokens.
*/

fragment HEX_DIGIT: [0-9a-fA-F];
fragment INTEGER_LITERAL: Digit+;
fragment NUMERIC_LITERAL: Digit+ ('.' Digit*)?;
fragment ALL_LITERAL_VALUE: StringLiteral | INTEGER_LITERAL | NUMERIC_LITERAL | NULL | TRUE
   | FALSE
   | NOW;


fragment ANY_NAME: SqlName | StringLiteral | LPAREN ANY_NAME RPAREN;
fragment A: [aA];
fragment B: [bB];
fragment C: [cC];
fragment D: [dD];
fragment E: [eE];
fragment F: [fF];
fragment G: [gG];
fragment H: [hH];
fragment I: [iI];
fragment J: [jJ];
fragment K: [kK];
fragment L: [lL];
fragment M: [mM];
fragment N: [nN];
fragment O: [oO];
fragment P: [pP];
fragment Q: [qQ];
fragment R: [rR];
fragment S: [sS];
fragment T: [tT];
fragment U: [uU];
fragment V: [vV];
fragment W: [wW];
fragment X: [xX];
fragment Y: [yY];
fragment Z: [zZ];

/**
 * Parser (ie structure)
 * https://github.com/antlr/antlr4/blob/master/doc/parser-rules.md
*/

sqlNames : SqlName|Number;

column: sqlNames (DOT sqlNames)? (AS (sqlNames|StringLiteral))?;

pattern: (StringLiteral|NumberLiteral);


expression:
    (SqlName|StringLiteral|NumberLiteral|Number)
    | functionNames LPAREN expression? ( COMMA expression)* RPAREN
;

predicate: sqlNames
    (
        (( LESS_THAN | LESS_THAN_OR_EQUAL | GREATER_THAN | GREATER_THAN_OR_EQUAL | NOT_EQUAL | EQUAL) expression)
        |
        (
            NOT?
            (LIKE pattern (ESCAPE StringLiteral)?)
            |
            (GLOB pattern)
        )
        |
        (NOT? BETWEEN expression AND expression)
        |
        (NOT? IN LPAREN (expression ( COMMA expression)*)? RPAREN)
    );

columns: column (COMMA column)*;

predicates: WHERE predicate ((AND|OR) predicate)*;

tables: FROM tableNames;

/**
 * The type of the literal value is
 * checked afterwards on tree traversing
 * otherwise there is conflict between token
*/
limit: LIMIT Number;

orderBys: ORDER BY orderByDef (COMMA orderByDef)* ;

orderByDef: SqlName (ASC | DESC)? ;

/**
* The main/root rule
*/
pageSql:
        SELECT
        RANDOM?
        columns
        tables?
        predicates?
        orderBys?
        limit?
;
