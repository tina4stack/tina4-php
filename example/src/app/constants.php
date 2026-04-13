<?php

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
