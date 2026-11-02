# litoaguiar.com.br

Site pessoal de Lito Aguiar, publicado na Hostinger via Git (deploy automático a partir da branch `main`).

- `index.html` — página inicial (hub pessoal: apresentação, mentoria em destaque, conteúdo e newsletter)
- `robots.txt`, `sitemap.xml`, `favicon.png` — SEO básico; ao publicar nova edição da news, incluir a URL no `sitemap.xml`
- `mentoria/` — LP da mentoria Fluxo de Autoridade (`index.html` + `img/`)
- `news/` — arquivo da newsletter Do Dado à Decisão (`index.html` lista as edições; cada edição em `news/NN/index.html`)

Páginas estáticas, sem build. Toda alteração na `main` vai para o ar.

## Integrações
- `nfd.php` — últimos 3 episódios do No Fim do Dia (RSS do YouTube, cache 6h); a home mostra 3 episódios fixos se falhar
- `news/assinar.php` — inscrição na newsletter → conversão `news-site` no RD Marketing (+ cópia em `news/dados/`)
- `inc/rd-marketing.php` — envio de conversões; lê o token público de `rd-marketing.json` **fora do repositório** (pasta acima de `public_html`: `{"token_publico":"..."}`). A mentoria usa o mesmo arquivo.
