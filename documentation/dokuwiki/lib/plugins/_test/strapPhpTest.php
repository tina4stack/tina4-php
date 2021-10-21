<?php


/**
 *
 *
 * @group template_strap
 * @group templates
 */
class strapPhpTest extends DokuWikiTest
{


    /**
     * A simple test to test that the template is working
     * on every language
     */
    public function test_generator_base()
    {


        $pageId = 'start';
        saveWikiText($pageId, "Content", 'Header Test base');
        idx_addPage($pageId);

        $request = new TestRequest();
        $response = $request->get(array('id' => $pageId, '/doku.php'));
        $expected = 'DokuWiki';

        $generator = $response->queryHTML('meta[name="generator"]')->attr("content");
        $this->assertEquals($expected, $generator);


    }


}
