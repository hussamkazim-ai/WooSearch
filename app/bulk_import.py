from woocommerce import API
from app.config import settings
from app.models import Product

async def import_products_in_bulk():
    """Fetching all puslished products from Woo Commerce store"""
    try:
        wcapi = API(
            url=settings.url,
            consumer_key=settings.consumer_key,
            consumer_secret=settings.consumer_secret,
            version=settings.version
        )
    except Exception as e:
        print("error happened", e)

    published_products = []
    page = 1

    while True:
        response = wcapi.get(
            "products",
            params={"status": "publish", "page": page}
        )
        response.raise_for_status() # Raising an error if something wrong happens
        products = response.json()

        if not products: # Breaking when there are not products in the current page
            break

        for item in products:
            cleaned_description = item["description"].replace("<p>", "") # Cleaning description text
            cleaned_description = cleaned_description.replace("</p>\n", "")

            product_obj = Product(
                id=item["id"],
                title=item["name"],
                description=cleaned_description
            )
            published_products.append(product_obj)

        page += 1

    return published_products

