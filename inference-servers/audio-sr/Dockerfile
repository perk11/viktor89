# AudioSR audio super-resolution inference server.
#
# Reproduces the conda env documented in README.md (Python 3.10 + audiosr from git +
# PyTorch CUDA wheels + the ffmpeg CLI). PyTorch pip wheels bundle their own CUDA
# runtime, so — exactly like the host setup — no conda cudatoolkit is needed: the only
# host requirement is the NVIDIA driver, exposed to the container via --gpus all
# (nvidia-container-toolkit).
#
# Build:
#   docker build -t viktor89-audio-sr inference-servers/audio-sr
#
# Run (GPU passthrough is required for usable speed):
#   docker run --rm --gpus all -p 8240:8240 viktor89-audio-sr
#
# Persist the AudioSR checkpoint (downloads on first run) across restarts:
#   docker run --rm --gpus all -p 8240:8240 \
#     -v audiosr-cache:/root/.cache/audiosr viktor89-audio-sr
FROM continuumio/miniconda3:latest

ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONUNBUFFERED=1

# Create the audiosr conda env exactly as documented in README.md.
RUN conda create -n audiosr -y python=3.10 \
    && conda clean -afy

# Make the env the default so `python`/`pip` resolve to it for the rest of the build.
ENV PATH="/opt/conda/envs/audiosr/bin:${PATH}" \
    CONDA_DEFAULT_ENV=audiosr

# ffmpeg CLI (+ ffprobe): the wrapper re-encodes AudioSR's 48 kHz PCM output to
# OGG/Opus (libopus) for Telegram voice notes. Install into the env via conda-forge.
RUN conda install -n audiosr -y -c conda-forge ffmpeg \
    && conda clean -afy

# Python deps, mirroring README.md. Order matters: torch stack first, then audiosr
# from git (git main adds super_resolution_long_audio — chunked processing used by this
# wrapper for songs longer than --long_audio_threshold_s), then flask, then pin
# setuptools<81 so pkg_resources (imported by audiosr's old pinned librosa) still works.
RUN pip install --no-cache-dir torch torchaudio torchcodec matplotlib \
    && pip install --no-cache-dir git+https://github.com/haoheliu/versatile_audio_super_resolution.git \
    && pip install --no-cache-dir flask \
    && pip install --no-cache-dir "setuptools<81"

WORKDIR /app
COPY main.py .

# 8240 is the port used in README.md.
EXPOSE 8240

# AudioSR downloads its checkpoint (~hundreds of MB) to ~/.cache/audiosr on first run.
VOLUME ["/root/.cache/audiosr"]

# Bind 0.0.0.0 so the port is reachable from outside the container (the wrapper defaults
# to localhost, which is unreachable across the container boundary).
CMD ["python", "main.py", "--host", "0.0.0.0", "--port", "8240"]
