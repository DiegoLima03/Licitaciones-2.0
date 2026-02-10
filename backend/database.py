import os
from functools import lru_cache

from dotenv import load_dotenv
from supabase import Client, create_client


load_dotenv()


def _create_supabase_client() -> Client:
  """
  Crea una instancia de cliente Supabase utilizando variables de entorno.

  Espera encontrar en `.env`:
    - SUPABASE_URL
    - SUPABASE_KEY  (usa la clave secreta o service role, NO la public key)
  """
  url = os.environ.get("SUPABASE_URL")
  key = os.environ.get("SUPABASE_KEY")

  if not url or not key:
    raise RuntimeError(
      "Faltan las variables de entorno SUPABASE_URL / SUPABASE_KEY "
      "para conectar con Supabase."
    )

  return create_client(url, key)


@lru_cache(maxsize=1)
def get_supabase_client() -> Client:
  """
  Devuelve un cliente Supabase singleton para todo el proceso FastAPI.
  """
  return _create_supabase_client()


__all__ = ["get_supabase_client"]

