<?php

\Tina4\Router::get("/admin/products", function ($request, $response) {
    $products = (new Product())->all(500, 0);
    $categories = (new Category())->all(100, 0);
    return $response(storeRender("admin/products.twig", [
        "products" => array_map(fn($p) => $p->toDict(), $products),
        "categories" => array_map(fn($c) => $c->toDict(), $categories),
    ], $request));
})->middleware(["adminAuth"]);

\Tina4\Router::post("/admin/products", function ($request, $response) {
    $name = $request->body['name'] ?? '';
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim($slug, '-');

    Product::create([
        "name" => $name,
        "slug" => $slug,
        "description" => $request->body['description'] ?? '',
        "price" => (float) ($request->body['price'] ?? 0),
        "category_id" => (int) ($request->body['category_id'] ?? 0),
        "stock" => (int) ($request->body['stock'] ?? 0),
        "image_url" => $request->body['image_url'] ?? '',
        "is_active" => 1,
    ]);
    $request->session->flash("success", "Product created");
    return $response->redirect("/admin/products");
})->noAuth()->middleware(["adminAuth"]);

\Tina4\Router::get("/admin/products/{id}/edit", function ($request, $response) {
    $id = (int) $request->params['id'];
    $product = (new Product())->findById($id);
    if (!$product || !$product->id) {
        $request->session->flash("error", "Product not found");
        return $response->redirect("/admin/products");
    }
    $categories = (new Category())->all(100, 0);
    return $response(storeRender("admin/product_edit.twig", [
        "product" => $product->toDict(),
        "categories" => array_map(fn($c) => $c->toDict(), $categories),
    ], $request));
})->middleware(["adminAuth"]);

\Tina4\Router::post("/admin/products/{id}/edit", function ($request, $response) {
    $id = (int) $request->params['id'];
    $product = (new Product())->findById($id);
    if (!$product || !$product->id) {
        $request->session->flash("error", "Product not found");
        return $response->redirect("/admin/products");
    }

    $product->name = $request->body['name'] ?? $product->name;
    $product->slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($product->name));
    $product->slug = trim($product->slug, '-');
    $product->description = $request->body['description'] ?? $product->description;
    $product->price = (float) ($request->body['price'] ?? $product->price);
    $product->categoryId = (int) ($request->body['category_id'] ?? $product->categoryId);
    $product->stock = (int) ($request->body['stock'] ?? $product->stock);
    $product->imageUrl = $request->body['image_url'] ?? $product->imageUrl;
    $product->save();

    $request->session->flash("success", "Product updated");
    return $response->redirect("/admin/products");
})->noAuth()->middleware(["adminAuth"]);

\Tina4\Router::post("/admin/products/{id}/delete", function ($request, $response) {
    $id = (int) $request->params['id'];
    $product = (new Product())->findById($id);
    if ($product && $product->id) {
        $product->delete();
    }
    $request->session->flash("success", "Product deleted");
    return $response->redirect("/admin/products");
})->noAuth()->middleware(["adminAuth"]);
