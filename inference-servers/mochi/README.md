1. `conda create --name mochi python=3.10`
2. `conda activate mochi`
3. `pip install flask pynvml torch`
4. `git clone git@github.com:victorchall/genmoai-smol.git`
5. `cd genmoai-smol`
6. `pip install -e .`
This takes long time due to compilation of flash-attn. 
7. `conda install -c conda-forge gcc gxx
8. `conda install -c conda-forge libstdcxx-ng=12` Probably not needed, the previous line could be enough 

9. Download https://huggingface.co/genmo/mochi-1-preview/tree/main
10. Use This dit-config.yaml https://huggingface.co/genmo/mochi-1-preview/blob/e5c4b33fceb5eb744b6ac8524edd80101aff4749/dit-config.yaml
11. `/home/user/.conda/envs/mochi/bin/python /opt/viktor89/inference-servers/mochi/main.py --port 18108 --model_dir /opt/mochi-1-preview`

First run takes ~15 minutes before server is ready to list due to compilation of the model.
