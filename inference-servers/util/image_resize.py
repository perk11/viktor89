from io import BytesIO
from typing import Any

from PIL import Image
from PIL.Image import Resampling


def resize_if_needed(image_data: bytes,
                     source_max_width: int,
                     source_max_height: int) -> tuple[bytes, bool]:
    """
    Resize image bytes down to fit within source_max_width/source_max_height
    while keeping aspect ratio, only if the image is already larger.

    :param image_data: Original JPEG image as bytes.
    :param source_max_width: Maximum allowed width.
    :param source_max_height: Maximum allowed height.
    :return: image data as bytes, resized or not
    """
    with Image.open(BytesIO(image_data)) as img:
        orig_width, orig_height = img.size

        # If already within bounds, return original
        if orig_width <= source_max_width and orig_height <= source_max_height:
            return image_data, False

        # Compute scale factor to fit within max bounds
        scale = min(source_max_width / orig_width,
                    source_max_height / orig_height)
        new_size = (int(orig_width * scale), int(orig_height * scale))

        resized = img.resize(new_size, Resampling.LANCZOS)
        out_buf = BytesIO()
        resized.save(out_buf, format='PNG')
        return out_buf.getvalue(), True
