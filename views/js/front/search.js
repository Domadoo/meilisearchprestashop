listProducts = document.querySelectorAll('.thumbnail-container');

listProducts.forEach((element, index) => {
    element.addEventListener('click', function(event) {
        ajaxProductClick(this.parentElement, index);
    });
});

function ajaxProductClick(product, index) {
    console.log(page); 
    let position = 1 + index + 48*(parseInt(page, 10) - 1);
    console.log(position);
    const xhttp = new XMLHttpRequest();
    $url = base_url + "?token=1&action=productClick&id_product=" + product.dataset.idProduct + "&position=" + position;
    xhttp.onload = function() {
        if (this.status >= 200 && this.status < 300) {
            // Handle successful response
            console.log('Product click recorded successfully');
        } else {
            // Handle error response
            console.error('Error recording product click');
        }
    };
    xhttp.open("GET", $url, true);
    xhttp.getResponseHeader("Content-type", "text/html; charset=utf-8");
    xhttp.send();
}