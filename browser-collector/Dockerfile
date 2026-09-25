FROM mcr.microsoft.com/playwright:v1.55.0-noble

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY collector.mjs ./

CMD ["npm","run","collect"]
