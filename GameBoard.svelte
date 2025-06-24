<script>
  // ✅ Jucător 1
  export let player1 = {
    name: "IONUT",
    score: 12,
    penalties: 1,
    isMyTurn: true,
    resources: {
      wood: 5,
      clay: 3,
      reed: 2,
      stone: 4,
      sheep: 1,
      boar: 2,
      cow: 0
    },
    houses: [
      { type: "wood", occupied: true },
      { type: "wood", occupied: true }
    ]
  };

  // ✅ Jucător 2
  export let player2 = {
    name: "VICTOR",
    score: 10,
    penalties: 0,
    isMyTurn: false,
    resources: {
      wood: 2,
      clay: 1,
      reed: 1,
      stone: 2,
      sheep: 2,
      boar: 0,
      cow: 1
    },
    houses: [
      { type: "wood", occupied: true },
      { type: "wood", occupied: false }
    ]
  };

  // ✅ NUMĂR SLOTHOUSE pe jucător
  const totalSlots = 5;

  const boardTiles = Array(15).fill(null);
  const animalSlots = Array(4).fill(null);
</script>

<style>
  main {
    font-family: sans-serif;
    background: #ede4d0;
    padding: 2rem;
    color: #333;
  }

  .players {
    display: flex;
    justify-content: space-between;
    gap: 2rem;
    margin-bottom: 2rem;
  }

  .player {
    flex: 1 1 40%;
    background: #5a3e2b;
    color: #fff;
    border-radius: 10px;
    padding: 1rem 2rem;
  }

  .resources {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 1rem;
  }

  .resource {
    background: #f5e9c5;
    color: #333;
    border-radius: 8px;
    padding: 0.5rem 1rem;
    min-width: 70px;
    box-shadow: 0 2px 4px #0002;
  }

  .board {
    display: grid;
    grid-template-columns: repeat(5, 80px);
    gap: 10px;
    justify-content: center;
    margin: 2rem 0;
  }

  .tile {
    background: #8cb369;
    border-radius: 8px;
    height: 80px;
    box-shadow: inset 0 0 5px #0003;
  }

  .bottom {
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
    gap: 2rem;
  }

  .houses, .animals {
    flex: 1 1 200px;
  }

  .box {
    background: #5a3e2b;
    color: #fff;
    border-radius: 10px;
    padding: 1rem;
    display: flex;
    gap: 10px;
    justify-content: center;
  }

  .house, .animal-slot {
    width: 50px;
    height: 50px;
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 20px;
  }

  .house.wood { background: #c09050; }
  .house.clay { background: #b87333; }
  .house.stone { background: #888; }
  .house.empty {
    background: #ddd;
    border: 2px dashed #999;
  }

  .farmer {
    font-size: 16px;
    margin-top: -4px;
  }

  .animal-slot {
    background: #c09050;
  }
</style>

<main>
  <!-- ✅ SUS: ambii jucători în paralel -->
  <div class="players">
    <div class="player">
      <h2>{player1.name} {player1.isMyTurn ? "(Tu 🔵)" : ""}</h2>
      <p>Scor: {player1.score} | Penalități: {player1.penalties}</p>
      <div class="resources">
        <div class="resource">🪵 {player1.resources.wood}</div>
        <div class="resource">🧱 {player1.resources.clay}</div>
        <div class="resource">🎋 {player1.resources.reed}</div>
        <div class="resource">🪨 {player1.resources.stone}</div>
        <div class="resource">🐑 {player1.resources.sheep}</div>
        <div class="resource">🐗 {player1.resources.boar}</div>
        <div class="resource">🐄 {player1.resources.cow}</div>
      </div>
    </div>

    <div class="player">
      <h2>{player2.name} {player2.isMyTurn ? "(Tu 🔵)" : ""}</h2>
      <p>Scor: {player2.score} | Penalități: {player2.penalties}</p>
      <div class="resources">
        <div class="resource">🪵 {player2.resources.wood}</div>
        <div class="resource">🧱 {player2.resources.clay}</div>
        <div class="resource">🎋 {player2.resources.reed}</div>
        <div class="resource">🪨 {player2.resources.stone}</div>
        <div class="resource">🐑 {player2.resources.sheep}</div>
        <div class="resource">🐗 {player2.resources.boar}</div>
        <div class="resource">🐄 {player2.resources.cow}</div>
      </div>
    </div>
  </div>

  <!-- ✅ Tabla comună -->
  <div class="board">
    {#each boardTiles as _, i}
      <div class="tile"></div>
    {/each}
  </div>

  <!-- ✅ Jos: case și animale PENTRU AMBII -->
  <div class="bottom">
    <div class="houses box">
      {#each Array(totalSlots) as _, i}
        {#if player1.houses[i]}
          <div class="house {player1.houses[i].type}">
            <div class="farmer">{player1.houses[i].occupied ? "👨‍🌾" : ""}</div>
          </div>
        {:else}
          <div class="house empty"></div>
        {/if}
      {/each}
    </div>

    <div class="houses box">
      {#each Array(totalSlots) as _, i}
        {#if player2.houses[i]}
          <div class="house {player2.houses[i].type}">
            <div class="farmer">{player2.houses[i].occupied ? "👨‍🌾" : ""}</div>
          </div>
        {:else}
          <div class="house empty"></div>
        {/if}
      {/each}
    </div>

    <div class="animals box">
      {#each animalSlots as _, i}
        <div class="animal-slot">🐾</div>
      {/each}
    </div>
  </div>
</main>
