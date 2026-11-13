FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    FASTEMBED_CACHE_PATH=/models

WORKDIR /app

ARG DEV=0
COPY requirements.txt requirements-dev.txt ./
RUN pip install -r requirements.txt \
    && if [ "$DEV" = "1" ]; then pip install -r requirements-dev.txt; fi

# Modelo de embeddings embutido na imagem: o container roda sem acesso ao Hugging Face.
# Se o download falhar no build, o serviço sobe em modo BM25 e o /health avisa.
ARG EMBEDDING_MODEL=sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2
RUN mkdir -p /models && python -c "from fastembed import TextEmbedding; TextEmbedding('${EMBEDDING_MODEL}', cache_dir='/models')" \
    || echo "AVISO: modelo de embeddings não baixado; busca seguirá em BM25."
ENV HF_HUB_OFFLINE=1

RUN useradd --system --uid 10001 app && mkdir -p /data && chown -R app /data /models

COPY pyproject.toml ./
COPY app ./app
COPY tests ./tests

USER app
EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --retries=5 \
  CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://localhost:8000/health', timeout=3).status == 200 else 1)"

CMD ["uvicorn", "--factory", "app.main:app_factory", "--host", "0.0.0.0", "--port", "8000", "--proxy-headers"]
