# litoaguiar.com.br

Site pessoal de Lito Aguiar, publicado na Hostinger via Git (deploy automático a partir da branch `main`).

- `index.html` — página inicial (hub pessoal: apresentação, mentoria em destaque, conteúdo e newsletter)
- `robots.txt`, `sitemap.xml`, `favicon.png` — SEO básico; ao publicar nova edição da news, incluir a URL no `sitemap.xml`
- `mentoria/` — LP da mentoria Fluxo de Autoridade (`index.html` + `img/`)
- `news/` — arquivo da newsletter Do Dado à Decisão (`index.html` lista as edições; cada edição em `news/NN/index.html`)

Páginas estáticas, sem build. Toda alteração na `main` vai para o ar.
