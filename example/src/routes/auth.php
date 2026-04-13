<?php

\Tina4\Router::get("/login", function ($request, $response) {
    return $response(storeRender("storefront/login.twig", [
        "error" => $request->session->getFlash("error"),
    ], $request));
});

\Tina4\Router::post("/login", function ($request, $response) {
    $email = $request->body['email'] ?? '';
    $password = $request->body['password'] ?? '';

    $customers = (new Customer())->where("email = ?", [$email]);
    $customer = !empty($customers) ? $customers[0] : null;

    if ($customer && !\Tina4\Auth::checkPassword($password, $customer->passwordHash)) {
        $customer = null;
    }

    if (!$customer) {
        $request->session->flash("error", "Invalid email or password");
        return $response->redirect("/login");
    }

    $role = $customer->role ?: "customer";
    $token = \Tina4\Auth::getToken(["customer_id" => $customer->id, "role" => $role]);
    $request->session->set("token", $token);
    $request->session->set("customer_id", $customer->id);
    $request->session->set("customer_name", $customer->name);
    $request->session->set("role", $role);

    if ($role === "admin") {
        return $response->redirect("/admin");
    }
    return $response->redirect("/account");
})->noAuth();

\Tina4\Router::get("/register", function ($request, $response) {
    return $response(storeRender("storefront/register.twig", [
        "error" => $request->session->getFlash("error"),
    ], $request));
});

\Tina4\Router::post("/register", function ($request, $response) {
    $name = $request->body['name'] ?? '';
    $email = $request->body['email'] ?? '';
    $password = $request->body['password'] ?? '';

    $existing = (new Customer())->where("email = ?", [$email]);
    if (!empty($existing)) {
        $request->session->flash("error", "Email already registered");
        return $response->redirect("/login");
    }

    $customer = Customer::create([
        "name" => $name,
        "email" => $email,
        "password_hash" => \Tina4\Auth::hashPassword($password),
        "role" => "customer",
    ]);

    \Tina4\Events::emit("customer.registered", [
        "customer_id" => $customer->id,
        "name" => $name,
        "email" => $email,
    ]);

    $token = \Tina4\Auth::getToken(["customer_id" => $customer->id, "role" => "customer"]);
    $request->session->set("token", $token);
    $request->session->set("customer_id", $customer->id);
    $request->session->set("customer_name", $name);
    $request->session->set("role", "customer");
    return $response->redirect("/account");
})->noAuth();

\Tina4\Router::get("/logout", function ($request, $response) {
    $request->session->set("token", null);
    $request->session->set("customer_id", null);
    $request->session->set("customer_name", null);
    $request->session->set("role", null);
    return $response->redirect("/");
});
