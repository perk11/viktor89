# UniverSR audio super-resolution inference server.
#
# Reproduces the conda env documented in README.md (Python 3.10 + universr from git +
# PyTorch CUDA wheels + the ffmpeg CLI). PyTorch pip wheels bundle their own CUDA
# runtime, so — exactly like the host setup — no conda cudatoolkit is needed: the only
# host requirement is the NVIDIA driver, exposed to the container via --gpus all
# (nvidia-container-toolkit).
#
# Build:
#   docker build -t viktor89-universr inference-servers/universr
#
# Run (GPU passthrough is required for usable speed):
#   docker run --rm --gpus all -p 8241:8241 viktor89-universr
#
# Persist the checkpoint (downloads on first run) across restarts:
#   docker run --rm --gpus all -p 8241:8241 \
#     -v universr-cache:/root/.cache/huggingface viktor89-universr
#
# The listening port is configurable via the PORT env var (default 8241); remember to also
# map it with -p, e.g.  -e PORT=9000 -p 9000:9000.
FROM continuumio/miniconda3:latest

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONUNBUFFERED=1 \
    PORT=8241

# Create the universr conda env exactly as documented in README.md.
RUN conda create -n universr -y python=3.10 \
    && conda clean -afy

# Make the env the default so `python`/`pip` resolve to it for the rest of the build.
ENV PATH="/opt/conda/envs/universr/bin:${PATH}" \
    CONDA_DEFAULT_ENV=universr

# ffmpeg CLI (+ ffprobe): the wrapper decodes arbitrary input (OGG/Opus, …) to a WAV that
# UniverSR can read, and re-encodes UniverSR's 48 kHz PCM output to OGG/Opus (libopus) for
# Telegram voice notes. Install into the env via conda-forge.
RUN conda install -n universr -y -c conda-forge ffmpeg \
    && conda clean -afy

# Python deps, mirroring the UniverSR pyproject. Order matters: torch stack first (the PyPI
# linux wheels are the CUDA builds and bundle their own CUDA runtime), then UniverSR itself
# from git (it is not published to PyPI), then flask for the HTTP wrapper.
RUN pip install --no-cache-dir torch torchaudio torchcodec \
    && pip install --no-cache-dir git+https://github.com/woongzip1/UniverSR.git \
    && pip install --no-cache-dir flask

WORKDIR /app
COPY main.py .

# 8241 is the default port (overridable via PORT); EXPOSE documents the default.
EXPOSE 8241

# UniverSR downloads its checkpoint (~a few hundred MB) to ~/.cache/huggingface on first run.
VOLUME ["/root/.cache/huggingface"]

# Bind 0.0.0.0 so the port is reachable from outside the container (the wrapper defaults to
# localhost, which is unreachable across the container boundary). PORT is overridable at
# run time with -e PORT=...
CMD ["sh", "-c", "python main.py --host 0.0.0.0 --port ${PORT}"]
