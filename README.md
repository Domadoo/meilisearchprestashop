# Meilisearchprestashop

## Statistics
```mermaid
sequenceDiagram
    autonumber
    loop navigation
      Note over Client: Search
      Client->>Database: Send query, id_customer, id_lang and nb_results
      Note over Client: Click on product
      Client->>Database: Send product_id and position
      Note over Client: Add to cart
      Client->>Database: Send id_cart
    end
    Note over Client: Checkout
    Client->>Database: Send is_ordered
```
