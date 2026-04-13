<?php

/**
 * Contact page — demonstrates HtmlElement programmatic HTML builder.
 */

function buildContactForm(bool $success = false, ?string $error = null): string
{
    extract(\Tina4\HtmlElement::helpers());

    $children = [];
    $children[] = $_h2(["class" => "mb-3"], "Contact Us");
    $children[] = $_p(["class" => "text-muted mb-4"], "Have a question? Send us a message.");

    if ($success) {
        $children[] = $_div(["class" => "alert alert-success"], "Thank you! Your message has been sent.");
    }
    if ($error) {
        $children[] = $_div(["class" => "alert alert-danger"], $error);
    }

    $form = $_form(["method" => "POST", "action" => "/contact", "class" => "contact-form"],
        $_div(["class" => "form-group mb-3"],
            $_label(["for" => "name"], "Name"),
            $_input(["type" => "text", "id" => "name", "name" => "name", "class" => "form-control", "required" => "required", "placeholder" => "John Smith"])
        ),
        $_div(["class" => "form-group mb-3"],
            $_label(["for" => "email"], "Email"),
            $_input(["type" => "email", "id" => "email", "name" => "email", "class" => "form-control", "required" => "required", "placeholder" => "you@example.com"])
        ),
        $_div(["class" => "form-group mb-3"],
            $_label(["for" => "subject"], "Subject"),
            $_input(["type" => "text", "id" => "subject", "name" => "subject", "class" => "form-control", "placeholder" => "Order enquiry, product question, etc."])
        ),
        $_div(["class" => "form-group mb-3"],
            $_label(["for" => "message"], "Message"),
            $_textarea(["id" => "message", "name" => "message", "class" => "form-control", "rows" => "5", "required" => "required", "placeholder" => "Tell us how we can help..."])
        ),
        $_button(["type" => "submit", "class" => "btn btn-primary"], "Send Message")
    );
    $children[] = $form;

    return (string) $_div(["class" => "container py-4", "style" => "max-width:600px;"], ...$children);
}

\Tina4\Router::get("/contact", function ($request, $response) {
    $html = buildContactForm();
    return $response(storeRender("base_wrap.twig", ["inner_html" => $html], $request));
});

\Tina4\Router::post("/contact", function ($request, $response) {
    $name = trim($request->body['name'] ?? '');
    $email = trim($request->body['email'] ?? '');
    $message = trim($request->body['message'] ?? '');

    if (!$name || !$email || !$message) {
        $html = buildContactForm(false, "Please fill in all required fields.");
        return $response(storeRender("base_wrap.twig", ["inner_html" => $html], $request));
    }

    $html = buildContactForm(true);
    return $response(storeRender("base_wrap.twig", ["inner_html" => $html], $request));
})->noAuth();
