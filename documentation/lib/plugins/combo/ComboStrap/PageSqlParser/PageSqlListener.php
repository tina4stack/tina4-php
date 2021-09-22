<?php

/*
 * Generated from D:/dokuwiki/lib/plugins/combo/grammar\PageSql.g4 by ANTLR 4.9.1
 */

namespace ComboStrap\PageSqlParser;
use Antlr\Antlr4\Runtime\Tree\ParseTreeListener;

/**
 * This interface defines a complete listener for a parse tree produced by
 * {@see PageSqlParser}.
 */
interface PageSqlListener extends ParseTreeListener {
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::functionNames()}.
	 * @param $context The parse tree.
	 */
	public function enterFunctionNames(Context\FunctionNamesContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::functionNames()}.
	 * @param $context The parse tree.
	 */
	public function exitFunctionNames(Context\FunctionNamesContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::tableNames()}.
	 * @param $context The parse tree.
	 */
	public function enterTableNames(Context\TableNamesContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::tableNames()}.
	 * @param $context The parse tree.
	 */
	public function exitTableNames(Context\TableNamesContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::sqlNames()}.
	 * @param $context The parse tree.
	 */
	public function enterSqlNames(Context\SqlNamesContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::sqlNames()}.
	 * @param $context The parse tree.
	 */
	public function exitSqlNames(Context\SqlNamesContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::column()}.
	 * @param $context The parse tree.
	 */
	public function enterColumn(Context\ColumnContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::column()}.
	 * @param $context The parse tree.
	 */
	public function exitColumn(Context\ColumnContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::pattern()}.
	 * @param $context The parse tree.
	 */
	public function enterPattern(Context\PatternContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::pattern()}.
	 * @param $context The parse tree.
	 */
	public function exitPattern(Context\PatternContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::expression()}.
	 * @param $context The parse tree.
	 */
	public function enterExpression(Context\ExpressionContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::expression()}.
	 * @param $context The parse tree.
	 */
	public function exitExpression(Context\ExpressionContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::predicate()}.
	 * @param $context The parse tree.
	 */
	public function enterPredicate(Context\PredicateContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::predicate()}.
	 * @param $context The parse tree.
	 */
	public function exitPredicate(Context\PredicateContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::columns()}.
	 * @param $context The parse tree.
	 */
	public function enterColumns(Context\ColumnsContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::columns()}.
	 * @param $context The parse tree.
	 */
	public function exitColumns(Context\ColumnsContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::predicates()}.
	 * @param $context The parse tree.
	 */
	public function enterPredicates(Context\PredicatesContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::predicates()}.
	 * @param $context The parse tree.
	 */
	public function exitPredicates(Context\PredicatesContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::tables()}.
	 * @param $context The parse tree.
	 */
	public function enterTables(Context\TablesContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::tables()}.
	 * @param $context The parse tree.
	 */
	public function exitTables(Context\TablesContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::limit()}.
	 * @param $context The parse tree.
	 */
	public function enterLimit(Context\LimitContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::limit()}.
	 * @param $context The parse tree.
	 */
	public function exitLimit(Context\LimitContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::orderBys()}.
	 * @param $context The parse tree.
	 */
	public function enterOrderBys(Context\OrderBysContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::orderBys()}.
	 * @param $context The parse tree.
	 */
	public function exitOrderBys(Context\OrderBysContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::orderByDef()}.
	 * @param $context The parse tree.
	 */
	public function enterOrderByDef(Context\OrderByDefContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::orderByDef()}.
	 * @param $context The parse tree.
	 */
	public function exitOrderByDef(Context\OrderByDefContext $context) : void;
	/**
	 * Enter a parse tree produced by {@see PageSqlParser::pageSql()}.
	 * @param $context The parse tree.
	 */
	public function enterPageSql(Context\PageSqlContext $context) : void;
	/**
	 * Exit a parse tree produced by {@see PageSqlParser::pageSql()}.
	 * @param $context The parse tree.
	 */
	public function exitPageSql(Context\PageSqlContext $context) : void;
}