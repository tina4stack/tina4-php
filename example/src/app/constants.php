<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


// Roles
const ROLE_CUSTOMER = "customer";
const ROLE_ADMIN = "admin";

// Order statuses
const STATUS_PENDING = "pending";
const STATUS_PROCESSING = "processing";
const STATUS_SHIPPED = "shipped";
const STATUS_DELIVERED = "delivered";
const STATUS_CANCELLED = "cancelled";

const ORDER_STATUSES = [STATUS_PENDING, STATUS_PROCESSING, STATUS_SHIPPED, STATUS_DELIVERED, STATUS_CANCELLED];

// Limits
const PRODUCTS_PER_PAGE = 12;
const ORDERS_PER_PAGE = 20;
const LOW_STOCK_THRESHOLD = 5;
const MAX_UPLOAD_SIZE = 5 * 1024 * 1024; // 5MB

const ALLOWED_IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];
