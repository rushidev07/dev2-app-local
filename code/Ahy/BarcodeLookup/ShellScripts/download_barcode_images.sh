#!/bin/bash

# Download product images for the BarcodeLookup module.
# Reads barcode-product-image.csv (upc_number, base_image_url, additional_image_urls),
# downloads images to pub/media/catalog/product/barcode_images/,
# and writes local paths to barcode_product_image_path.csv.
#
# Usage: download_barcode_images.sh <base_folder_path> <source_dir>
#   base_folder_path  - Magento root directory (with trailing slash)
#   source_dir        - Directory containing barcode-product-image.csv

if [ "$#" -lt 2 ]; then
    echo "Usage: $0 <base_folder_path> <source_dir>"
    exit 1
fi

BASE_FOLDER_PATH="$1"
SOURCE_DIR="$2"

CSV_PATH="$SOURCE_DIR/barcode-product-image.csv"
OUTPUT_CSV="$SOURCE_DIR/barcode_product_image_path.csv"
IMAGE_DIR="${BASE_FOLDER_PATH}pub/media/catalog/product/barcode_images"
PREFIX_TO_REMOVE="${BASE_FOLDER_PATH}pub/media/catalog/product"

if [ ! -f "$CSV_PATH" ]; then
    echo "Warning: Barcode image CSV not found at $CSV_PATH. Skipping image download."
    exit 0
fi

# Create image directory if it does not exist
if [ ! -d "$IMAGE_DIR" ]; then
    echo "Creating directory $IMAGE_DIR..."
    mkdir -p "$IMAGE_DIR"
    if [ $? -ne 0 ]; then
        echo "Error: Failed to create directory $IMAGE_DIR. Exiting."
        exit 1
    fi
fi

# Initialise the output CSV
> "$OUTPUT_CSV"

while IFS=',' read -r UPC_NUMBER BASE_IMAGE ADDITIONAL_IMAGES; do
    # Skip the header line
    if [ "$UPC_NUMBER" = "upc_number" ]; then
        echo "$UPC_NUMBER,$BASE_IMAGE,$ADDITIONAL_IMAGES" >> "$OUTPUT_CSV"
        continue
    fi

    # Download base image
    if [ -z "$BASE_IMAGE" ]; then
        echo "$UPC_NUMBER,," >> "$OUTPUT_CSV"
        echo "Warning: No base image URL for UPC $UPC_NUMBER. Skipping."
        continue
    fi

    BASE_FILENAME="$IMAGE_DIR/$(basename "$BASE_IMAGE" | awk -F '?' '{print $1}')"

    if [ ! -f "$BASE_FILENAME" ]; then
        echo "Downloading base image for UPC $UPC_NUMBER..."
        if ! curl -s -o "$BASE_FILENAME" "$BASE_IMAGE"; then
            echo "Error: Failed to download base image for UPC $UPC_NUMBER. Skipping."
            echo "$UPC_NUMBER,," >> "$OUTPUT_CSV"
            continue
        fi
    else
        echo "Base image for UPC $UPC_NUMBER already exists. Skipping download."
    fi

    # Convert absolute path to relative (strip the media/catalog/product prefix)
    BASE_RELATIVE_PATH="${BASE_FILENAME#"$PREFIX_TO_REMOVE"}"

    # Download additional images
    ADDITIONAL_FILENAMES=()

    if [ -n "$ADDITIONAL_IMAGES" ]; then
        IFS=' | ' read -ra ADDITIONAL_IMAGES_ARRAY <<< "$ADDITIONAL_IMAGES"
        for ADDITIONAL_IMAGE in "${ADDITIONAL_IMAGES_ARRAY[@]}"; do
            ADDITIONAL_IMAGE=$(echo "$ADDITIONAL_IMAGE" | tr -d '\r\n')
            [ -z "$ADDITIONAL_IMAGE" ] && continue

            ADDITIONAL_FILENAME="$IMAGE_DIR/$(basename "$ADDITIONAL_IMAGE" | awk -F '?' '{print $1}')"

            if [ ! -f "$ADDITIONAL_FILENAME" ]; then
                echo "Downloading additional image for UPC $UPC_NUMBER..."
                if ! curl -s -o "$ADDITIONAL_FILENAME" "$ADDITIONAL_IMAGE"; then
                    echo "Error: Failed to download additional image for UPC $UPC_NUMBER. Skipping."
                    continue
                fi
            else
                echo "Additional image for UPC $UPC_NUMBER already exists. Skipping download."
            fi

            ADDITIONAL_RELATIVE="${ADDITIONAL_FILENAME#"$PREFIX_TO_REMOVE"}"
            ADDITIONAL_FILENAMES+=("$ADDITIONAL_RELATIVE")
        done
    fi

    # Join additional paths with ' | ' separator
    ADDITIONAL_CONCAT=$(IFS=' | '; echo "${ADDITIONAL_FILENAMES[*]}")

    echo "$UPC_NUMBER,$BASE_RELATIVE_PATH,$ADDITIONAL_CONCAT" >> "$OUTPUT_CSV"

done < "$CSV_PATH"

echo "Barcode image download complete. Results saved in $OUTPUT_CSV"